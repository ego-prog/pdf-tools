<?php

declare(strict_types=1);

/**
 * Substitui assinaturas digitais por um aviso visível.
 *
 * Toda operação que reescreve o PDF invalida a assinatura digital (ela
 * cobre os bytes exatos do original). Em vez de entregar uma cópia com
 * um campo de assinatura inválido, o PDF Tools:
 *
 *   1. remove os campos de assinatura (widget, campo do formulário,
 *      /SigFlags e /Perms);
 *   2. desenha um aviso — "Assinado digitalmente no original em
 *      12/03/2026 por Fulano (CPF 123.456.789-01)." — sobre o local da
 *      assinatura, quando ela era visível, ou numa faixa acrescentada à
 *      borda inferior de cada página, sem cobrir o conteúdo.
 *
 * Data e nome vêm do próprio PDF (/M e /Name do dicionário da assinatura,
 * ou o titular do certificado no CMS); o CPF/CNPJ vem do certificado. O
 * arquivo original não é alterado.
 */
final class PdfSignatureNotice
{
    private const FONT = '/PdfToolsAviso';

    // Faixa na borda da página.
    private const MARGIN_FONT_SIZE = 7.0;
    private const MARGIN_LINE_HEIGHT = 8.5;
    private const MARGIN_PADDING_Y = 4.0;
    private const MARGIN_PADDING_X = 12.0;

    // Aviso sobre a assinatura visível: tenta do maior ao menor tamanho.
    private const BOX_MAX_FONT_SIZE = 9.0;
    private const BOX_MIN_FONT_SIZE = 4.0;
    private const BOX_PADDING = 3.0;

    // Widgets menores que isso (pt) são assinaturas invisíveis.
    private const MIN_VISIBLE_SIZE = 2.0;

    // Bits de /F (anotação): 2 = Hidden, 6 = NoView.
    private const HIDDEN_FLAGS = 0b100010;

    public function __construct(
        private readonly PdfProcess $process
    ) {}

    /**
     * Grava em $outputFile a cópia com os avisos, se o PDF tiver
     * assinatura digital. Senha só para PDFs que exigem senha para abrir;
     * a cópia sai sem criptografia.
     *
     * @return int Número de assinaturas encontradas. 0 = PDF sem
     *             assinatura; nada é gravado.
     */
    public function apply(string $inputFile, string $outputFile, string $password = ''): int
    {
        $workDirectory = dirname($outputFile);
        $passwordArgs  = $password === '' ? [] : ['--password=' . $password];

        $jsonFile   = $outputFile . '.json';
        $updateFile = $outputFile . '.update.json';

        try {
            $this->process->runQpdf(
                [$inputFile, ...$passwordArgs, '--json-output', '--json-stream-data=none', $jsonFile],
                $workDirectory,
                allowWarnings: true
            );

            $objects = new PdfObjects((string) file_get_contents($jsonFile));
            @unlink($jsonFile);

            $pages      = $this->pages($objects);
            $widgets    = $this->signatureWidgets($objects, $pages);
            $signatures = $this->signatures($objects, $widgets);

            if ($signatures === []) {
                return 0;
            }

            $this->removeSignatureFields($objects, $pages, $widgets);
            $this->drawNotices($objects, $pages, $widgets, $signatures);

            file_put_contents($updateFile, $objects->updateJson());

            $this->process->runQpdf(
                [
                    $inputFile,
                    ...$passwordArgs,
                    '--decrypt',
                    '--update-from-json=' . $updateFile,
                    $outputFile,
                ],
                $workDirectory,
                allowWarnings: true
            );
        } finally {
            @unlink($jsonFile);
            @unlink($updateFile);
        }

        if (!is_file($outputFile) || filesize($outputFile) === 0) {
            throw new RuntimeException('A cópia com o aviso de assinatura não foi criada.');
        }

        return count($signatures);
    }

    /* ------------------------------------------------------------
     * Leitura
     * ---------------------------------------------------------- */

    /**
     * Páginas na ordem do documento, com os atributos herdados resolvidos.
     *
     * @return list<array{ref: string, mediaBox: array, cropBox: ?array, rotate: int, resources: mixed}>
     */
    private function pages(PdfObjects $objects): array
    {
        $root  = $objects->resolve($objects->trailer()->{'/Root'} ?? null);
        $pages = [];

        if (!$root instanceof stdClass) {
            return [];
        }

        $visited = [];
        $walk    = function (mixed $nodeRef, array $inherited, int $depth) use (&$walk, &$pages, &$visited, $objects): void {
            if (!PdfObjects::isRef($nodeRef) || isset($visited[$nodeRef]) || $depth > 64) {
                return;
            }

            $visited[$nodeRef] = true;
            $node = $objects->resolve($nodeRef);

            if (!$node instanceof stdClass) {
                return;
            }

            foreach (['/MediaBox', '/CropBox', '/Rotate', '/Resources'] as $key) {
                if (property_exists($node, $key)) {
                    $inherited[$key] = $node->{$key};
                }
            }

            $kids = $objects->resolve($node->{'/Kids'} ?? null);

            if (is_array($kids) && ($node->{'/Type'} ?? '/Pages') !== '/Page') {
                foreach ($kids as $kid) {
                    $walk($kid, $inherited, $depth + 1);
                }

                return;
            }

            $mediaBox = self::box($objects->resolve($inherited['/MediaBox'] ?? null))
                ?? [0.0, 0.0, 612.0, 792.0];
            $cropBox  = self::box($objects->resolve($inherited['/CropBox'] ?? null));
            $rotate   = $objects->resolve($inherited['/Rotate'] ?? 0);

            $pages[] = [
                'ref'       => $nodeRef,
                'mediaBox'  => $mediaBox,
                'cropBox'   => $cropBox,
                'rotate'    => ((int) (is_numeric($rotate) ? $rotate : 0) % 360 + 360) % 360,
                'resources' => $inherited['/Resources'] ?? null,
            ];
        };

        $walk($root->{'/Pages'} ?? null, [], 0);

        return $pages;
    }

    /**
     * Widgets de campos de assinatura preenchidos (com /V contendo /ByteRange).
     *
     * @return list<array{page: int, annotIndex: int, field: ?string, signature: string, rect: ?array, visible: bool}>
     */
    private function signatureWidgets(PdfObjects $objects, array $pages): array
    {
        $widgets = [];

        foreach ($pages as $pageIndex => $page) {
            $pageDict = $objects->resolve($page['ref']);
            $annots   = $objects->resolve($pageDict->{'/Annots'} ?? null);

            if (!is_array($annots)) {
                continue;
            }

            foreach ($annots as $annotIndex => $annot) {
                $widget = $objects->resolve($annot);

                if (!$widget instanceof stdClass || ($widget->{'/Subtype'} ?? null) !== '/Widget') {
                    continue;
                }

                // Sobe pela hierarquia (/Parent) atrás de /FT, /V e do campo.
                $fieldType = null;
                $value     = null;
                $field     = null;
                $node      = $widget;
                $nodeRef   = PdfObjects::isRef($annot) ? $annot : null;

                for ($depth = 0; $node instanceof stdClass && $depth < 32; $depth++) {
                    $fieldType ??= $node->{'/FT'} ?? null;
                    $value     ??= $node->{'/V'} ?? null;

                    if ($field === null && property_exists($node, '/T')) {
                        $field = $nodeRef;
                    }

                    $nodeRef = $node->{'/Parent'} ?? null;
                    $node    = $objects->resolve($nodeRef);
                }

                if ($fieldType !== '/Sig' || !PdfObjects::isRef($value)) {
                    continue;
                }

                $signature = $objects->resolve($value);

                if (!$signature instanceof stdClass || !property_exists($signature, '/ByteRange')) {
                    continue;
                }

                $rect  = self::box($objects->resolve($widget->{'/Rect'} ?? null));
                $flags = (int) ($objects->resolve($widget->{'/F'} ?? 0) ?? 0);

                $widgets[] = [
                    'page'       => $pageIndex,
                    'annotIndex' => $annotIndex,
                    'field'      => $field ?? (PdfObjects::isRef($annot) ? $annot : null),
                    'signature'  => $value,
                    'rect'       => $rect,
                    'visible'    => $rect !== null
                        && $rect[2] - $rect[0] >= self::MIN_VISIBLE_SIZE
                        && $rect[3] - $rect[1] >= self::MIN_VISIBLE_SIZE
                        && ($flags & self::HIDDEN_FLAGS) === 0,
                ];
            }
        }

        return $widgets;
    }

    /**
     * Assinaturas do documento: as dos widgets e também dicionários de
     * assinatura soltos (sem campo visível nas páginas), que também
     * indicam que o original era assinado.
     *
     * @return array<string, array{name: string, document: string, date: string}> referência => dados
     */
    private function signatures(PdfObjects $objects, array $widgets): array
    {
        $signatures = [];

        foreach ($widgets as $widget) {
            $signatures[$widget['signature']] = null;
        }

        foreach ($objects->values() as $ref => $value) {
            if (
                $value instanceof stdClass
                && property_exists($value, '/ByteRange')
                && property_exists($value, '/Contents')
                && in_array($value->{'/Type'} ?? '/Sig', ['/Sig', '/DocTimeStamp'], true)
            ) {
                $signatures[$ref] ??= null;
            }
        }

        foreach (array_keys($signatures) as $ref) {
            $signatures[$ref] = $this->signatureInfo($objects->resolve($ref));
        }

        return $signatures;
    }

    /** @return array{name: string, document: string, date: string} */
    private function signatureInfo(mixed $signature): array
    {
        if (!$signature instanceof stdClass) {
            return ['name' => '', 'document' => '', 'date' => ''];
        }

        [$certificateName, $document] = self::certificateHolder(
            PdfObjects::bytes($signature->{'/Contents'} ?? null)
        );

        $name = self::clean(PdfObjects::text($signature->{'/Name'} ?? null));

        return [
            'name'     => $name !== '' ? $name : $certificateName,
            'document' => $document,
            'date'     => self::formatDate(PdfObjects::text($signature->{'/M'} ?? null)),
        ];
    }

    /**
     * Titular do certificado do signatário no CMS: nome (CN) e CPF ou CNPJ.
     *
     * O documento vem dos campos ICP-Brasil do certificado (otherName no
     * subjectAltName): 2.16.76.1.3.1 no e-CPF (nascimento + CPF + ...) e
     * 2.16.76.1.3.3 no e-CNPJ (CNPJ). Sem eles, tenta o formato
     * "NOME:CPF" no CN.
     *
     * @return array{0: string, 1: string} [nome, "CPF 123.456.789-01" ou "CNPJ ..." ou ""]
     */
    private static function certificateHolder(string $cms): array
    {
        if (strlen($cms) < 4 || $cms[0] !== "\x30" || !function_exists('openssl_pkcs7_read')) {
            return ['', ''];
        }

        // /Contents vem completado com zeros até o tamanho reservado. Não
        // dá para recortar: no CMS em BER de tamanho indefinido (30 80 ...)
        // o fim é marcado justamente por zeros. O OpenSSL ignora o excesso.
        $pem = "-----BEGIN PKCS7-----\n" . chunk_split(base64_encode($cms), 64, "\n")
            . "-----END PKCS7-----\n";

        $certificates = [];

        if (!@openssl_pkcs7_read($pem, $certificates)) {
            while (openssl_error_string() !== false) {
                // Descarta erros acumulados do OpenSSL.
            }

            return ['', ''];
        }

        $parsed = [];

        foreach ($certificates as $certificate) {
            $info = @openssl_x509_parse($certificate);

            if (is_array($info)) {
                $parsed[] = ['info' => $info, 'pem' => $certificate];
            }
        }

        // O certificado do signatário é o que não emitiu nenhum dos
        // outros (a cadeia pode vir junto no CMS).
        foreach ($parsed as $index => $certificate) {
            foreach ($parsed as $otherIndex => $other) {
                if ($otherIndex !== $index && ($other['info']['issuer'] ?? []) === ($certificate['info']['subject'] ?? [])) {
                    continue 2;
                }
            }

            $commonName = $certificate['info']['subject']['CN'] ?? '';
            $commonName = is_array($commonName) ? (string) reset($commonName) : (string) $commonName;

            $der = (string) base64_decode(
                preg_replace('/-----[A-Z ]+-----|\s+/', '', $certificate['pem']) ?? ''
            );

            $cpf  = substr(self::icpBrasilField($der, 1), 8, 11);
            $cnpj = substr(self::icpBrasilField($der, 3), 0, 14);

            if (preg_match('/^(.*?)\s*:\s*(\d{11}|\d{14})\s*$/', $commonName, $match)) {
                $commonName = $match[1];

                if (strlen($match[2]) === 11) {
                    $cpf = self::validNumber($cpf, 11) ? $cpf : $match[2];
                } else {
                    $cnpj = self::validNumber($cnpj, 14) ? $cnpj : $match[2];
                }
            }

            // Espaço inseparável: "CPF" e o número ficam na mesma linha.
            $document = match (true) {
                self::validNumber($cpf, 11)  => "CPF\u{A0}" . preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $cpf),
                self::validNumber($cnpj, 14) => "CNPJ\u{A0}" . preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $cnpj),
                default                      => '',
            };

            return [self::clean($commonName), $document];
        }

        return ['', ''];
    }

    /**
     * Conteúdo do otherName ICP-Brasil 2.16.76.1.3.$field no certificado
     * (DER). Estrutura: OID, [0] { OCTET STRING | PrintableString | UTF8String }.
     */
    private static function icpBrasilField(string $der, int $field): string
    {
        $oid    = "\x06\x05\x60\x4C\x01\x03" . chr($field);
        $offset = strpos($der, $oid);

        if ($offset === false) {
            return '';
        }

        $position = $offset + strlen($oid);

        if (($der[$position] ?? '') !== "\xA0") {
            return '';
        }

        $position = self::skipLength($der, $position + 1);

        if ($position === null || !in_array($der[$position] ?? '', ["\x04", "\x13", "\x0C"], true)) {
            return '';
        }

        $start = self::skipLength($der, $position + 1);

        if ($start === null) {
            return '';
        }

        $lengthByte = ord($der[$position + 1]);
        $length     = $lengthByte < 0x80
            ? $lengthByte
            : (int) hexdec(bin2hex(substr($der, $position + 2, $lengthByte & 0x7F)));

        return substr($der, $start, $length);
    }

    /** Posição logo após o campo de tamanho DER que começa em $position. */
    private static function skipLength(string $der, int $position): ?int
    {
        if (!isset($der[$position])) {
            return null;
        }

        $byte = ord($der[$position]);

        return $byte < 0x80 ? $position + 1 : $position + 1 + ($byte & 0x7F);
    }

    /** Só dígitos, no tamanho certo, e não tudo zero (campo não preenchido). */
    private static function validNumber(string $number, int $length): bool
    {
        return preg_match('/^\d{' . $length . '}$/', $number) === 1 && trim($number, '0') !== '';
    }

    /** "D:20260312101500-03'00'" → "12/03/2026" */
    private static function formatDate(string $date): string
    {
        if (!preg_match('/^(?:D:)?(\d{4})(\d{2})(\d{2})/', $date, $match)) {
            return '';
        }

        [, $year, $month, $day] = $match;

        return checkdate((int) $month, (int) $day, (int) $year) ? "{$day}/{$month}/{$year}" : '';
    }

    private static function clean(string $text): string
    {
        $text = trim(preg_replace('/[\p{C}\s]+/u', ' ', $text) ?? '');

        return mb_strlen($text) > 80 ? rtrim(mb_substr($text, 0, 79)) . '…' : $text;
    }

    /* ------------------------------------------------------------
     * Remoção dos campos de assinatura
     * ---------------------------------------------------------- */

    private function removeSignatureFields(PdfObjects $objects, array $pages, array $widgets): void
    {
        // Widgets das páginas.
        $byPage = [];

        foreach ($widgets as $widget) {
            $byPage[$widget['page']][$widget['annotIndex']] = true;
        }

        foreach ($byPage as $pageIndex => $indices) {
            $pageRef = $pages[$pageIndex]['ref'];
            $page    = $objects->resolve($pageRef);
            $annots  = $objects->resolve($page->{'/Annots'} ?? null);

            $remaining = array_values(array_filter(
                is_array($annots) ? $annots : [],
                static fn (int $index) => !isset($indices[$index]),
                ARRAY_FILTER_USE_KEY
            ));

            if ($remaining === []) {
                unset($page->{'/Annots'});
            } else {
                $page->{'/Annots'} = $remaining;
            }

            $objects->touch($pageRef);
        }

        // Campos do formulário.
        $rootRef = $objects->trailer()->{'/Root'} ?? null;
        $root    = $objects->resolve($rootRef);

        if (!$root instanceof stdClass || !PdfObjects::isRef($rootRef)) {
            return;
        }

        foreach ($widgets as $widget) {
            if ($widget['field'] !== null) {
                $this->removeField($objects, $root, $widget['field']);
            }
        }

        // DocMDP/UR3 referenciam a assinatura removida.
        unset($root->{'/Perms'});

        $acroFormRef = $root->{'/AcroForm'} ?? null;
        $acroForm    = $objects->resolve($acroFormRef);

        if ($acroForm instanceof stdClass) {
            unset($acroForm->{'/SigFlags'});

            if (PdfObjects::isRef($acroFormRef)) {
                $objects->touch($acroFormRef);
            }
        }

        $objects->touch($rootRef);
    }

    /** Tira o campo da lista do pai (/Kids) ou do /AcroForm (/Fields). */
    private function removeField(PdfObjects $objects, stdClass $root, string $fieldRef, int $depth = 0): void
    {
        $field = $objects->resolve($fieldRef);

        if (!$field instanceof stdClass || $depth > 32) {
            return;
        }

        $parentRef = $field->{'/Parent'} ?? null;

        if (PdfObjects::isRef($parentRef)) {
            $parent = $objects->resolve($parentRef);

            if (!$parent instanceof stdClass) {
                return;
            }

            $kids = $this->withoutRef($objects, $parent, '/Kids', $fieldRef);
            $objects->touch($parentRef);

            // Pai que ficou sem filhos também sai.
            if ($kids === []) {
                $this->removeField($objects, $root, $parentRef, $depth + 1);
            }

            return;
        }

        $acroFormRef = $root->{'/AcroForm'} ?? null;
        $acroForm    = $objects->resolve($acroFormRef);

        if ($acroForm instanceof stdClass) {
            $this->withoutRef($objects, $acroForm, '/Fields', $fieldRef);

            if (PdfObjects::isRef($acroFormRef)) {
                $objects->touch($acroFormRef);
            }
        }
    }

    /**
     * Remove $ref do array $dict->$key (direto ou indireto).
     *
     * @return list<mixed> O array resultante
     */
    private function withoutRef(PdfObjects $objects, stdClass $dict, string $key, string $ref): array
    {
        $holder = $dict->{$key} ?? null;
        $array  = $objects->resolve($holder);

        $result = array_values(array_filter(
            is_array($array) ? $array : [],
            static fn (mixed $item) => $item !== $ref
        ));

        if (PdfObjects::isRef($holder)) {
            $objects->set($holder, $result);
        } else {
            $dict->{$key} = $result;
        }

        return $result;
    }

    /* ------------------------------------------------------------
     * Avisos
     * ---------------------------------------------------------- */

    private function drawNotices(PdfObjects $objects, array $pages, array $widgets, array $signatures): void
    {
        $fontRef = $objects->add((object) [
            '/Type'     => '/Font',
            '/Subtype'  => '/Type1',
            '/BaseFont' => '/Helvetica',
            '/Encoding' => '/WinAnsiEncoding',
        ]);

        $marginText = self::marginText(array_values($signatures));

        foreach ($pages as $pageIndex => $page) {
            $operators = '';
            $covered   = [];

            // Aviso sobre cada assinatura visível desta página.
            foreach ($widgets as $widget) {
                if ($widget['page'] !== $pageIndex || !$widget['visible']) {
                    continue;
                }

                $box = $this->boxNotice(
                    $widget['rect'],
                    $page['rotate'],
                    self::boxText($signatures[$widget['signature']])
                );

                if ($box !== null) {
                    $operators .= $box;
                    $covered[$widget['signature']] = true;
                }
            }

            $pageDict = $objects->resolve($page['ref']);

            // Faixa na borda, salvo se os avisos acima já citam todas as
            // assinaturas do documento.
            if (count($covered) < count($signatures)) {
                $operators .= $this->marginNotice($pageDict, $page, $marginText);
            }

            $this->appendContent($objects, $page, $pageDict, $operators, $fontRef);
            $objects->touch($page['ref']);
        }
    }

    /**
     * "Assinado digitalmente no original em 12/03/2026 por Fulano de Tal
     * (CPF 123.456.789-01)."
     *
     * @param array{name: string, document: string, date: string} $signature
     */
    private static function boxText(array $signature): string
    {
        $description = self::describe($signature);

        return 'Assinado digitalmente no original' . ($description === '' ? '' : ' ' . $description) . '.';
    }

    /**
     * Uma assinatura: o mesmo texto da caixa. Várias:
     * "Assinado digitalmente no original: em 12/03/2026 por A (CPF ...); em 13/03/2026 por B."
     *
     * @param list<array{name: string, document: string, date: string}> $signatures
     */
    private static function marginText(array $signatures): string
    {
        if (count($signatures) === 1) {
            return self::boxText($signatures[0]);
        }

        $items = array_map(
            static fn (array $signature): string => self::describe($signature) ?: 'assinatura sem identificação',
            $signatures
        );

        return 'Assinado digitalmente no original: ' . implode('; ', $items) . '.';
    }

    /**
     * "em 12/03/2026 por Fulano de Tal (CPF 123.456.789-01)"; partes
     * ausentes são omitidas.
     *
     * @param array{name: string, document: string, date: string} $signature
     */
    private static function describe(array $signature): string
    {
        $holder = match (true) {
            $signature['name'] !== '' && $signature['document'] !== '' => "{$signature['name']} ({$signature['document']})",
            default                                                    => $signature['name'] . $signature['document'],
        };

        return trim(
            ($signature['date'] !== '' ? 'em ' . $signature['date'] : '')
            . ($holder !== '' ? ' por ' . $holder : '')
        );
    }

    /**
     * Aviso dentro do retângulo da assinatura. Null se o texto não couber
     * nem no menor tamanho de fonte.
     */
    private function boxNotice(array $rect, int $rotate, string $text): ?string
    {
        [$matrix, $width, $height] = self::orient($rect, $rotate);

        $encoded = PdfHelvetica::encode($text);
        $inner   = $width - 2 * self::BOX_PADDING;
        $words   = explode(' ', $encoded);

        for ($size = self::BOX_MAX_FONT_SIZE; $size >= self::BOX_MIN_FONT_SIZE; $size -= 0.5) {
            // Nenhuma palavra (ex.: "CPF 123.456.789-01", unido por espaço
            // inseparável) pode ser cortada: diminui a fonte.
            foreach ($words as $word) {
                if (PdfHelvetica::width($word, $size) > $inner) {
                    continue 2;
                }
            }

            $lineHeight = $size * 1.2;
            $lines      = PdfHelvetica::wrap($encoded, $size, $inner);
            $textHeight = $size * 0.93 + (count($lines) - 1) * $lineHeight;

            if ($inner <= 0 || $textHeight > $height - 2 * self::BOX_PADDING) {
                continue;
            }

            // Bloco centralizado na caixa.
            $top = ($height + $textHeight) / 2 - $size * 0.72;

            return 'q ' . self::matrix($matrix) . " cm\n"
                . '1 g 0 0 ' . self::number($width) . ' ' . self::number($height) . " re f\n"
                . '0.35 G 0.8 w 0.4 0.4 ' . self::number($width - 0.8) . ' ' . self::number($height - 0.8) . " re S\n"
                . self::textLines($lines, $size, $lineHeight, $width, $top)
                . "Q\n";
        }

        return null;
    }

    /**
     * Acrescenta uma faixa à borda inferior (na orientação de leitura) da
     * página e desenha o aviso nela. Aumenta /MediaBox e /CropBox.
     */
    private function marginNotice(stdClass $pageDict, array $page, string $text): string
    {
        $visible = $page['cropBox'] ?? $page['mediaBox'];
        $rotate  = $page['rotate'];

        $readingWidth = $rotate % 180 === 0
            ? $visible[2] - $visible[0]
            : $visible[3] - $visible[1];

        $encoded = PdfHelvetica::encode($text);
        $lines   = PdfHelvetica::wrap(
            $encoded,
            self::MARGIN_FONT_SIZE,
            max(10.0, $readingWidth - 2 * self::MARGIN_PADDING_X)
        );

        $height = 2 * self::MARGIN_PADDING_Y + self::MARGIN_FONT_SIZE * 0.93
            + (count($lines) - 1) * self::MARGIN_LINE_HEIGHT;

        // Faixa fora da área atual, no lado que fica embaixo na leitura.
        [$x0, $y0, $x1, $y1] = $visible;

        [$strip, $grow] = match ($rotate) {
            90      => [[$x1, $y0, $x1 + $height, $y1], [2, $height]],
            180     => [[$x0, $y1, $x1, $y1 + $height], [3, $height]],
            270     => [[$x0 - $height, $y0, $x0, $y1], [0, -$height]],
            default => [[$x0, $y0 - $height, $x1, $y0], [1, -$height]],
        };

        [$side, $delta] = $grow;

        foreach (['/MediaBox' => $page['mediaBox'], '/CropBox' => $page['cropBox']] as $key => $box) {
            if ($box === null) {
                continue;
            }

            // A faixa parte da borda visível; a MediaBox cresce até ela.
            $box[$side] = $delta < 0
                ? min($box[$side], $visible[$side] + $delta)
                : max($box[$side], $visible[$side] + $delta);

            $pageDict->{$key} = $box;
        }

        [$matrix, $width, $stripHeight] = self::orient($strip, $rotate);

        $top = $stripHeight - self::MARGIN_PADDING_Y - self::MARGIN_FONT_SIZE * 0.72;

        return 'q ' . self::matrix($matrix) . " cm\n"
            . '1 g 0 0 ' . self::number($width) . ' ' . self::number($stripHeight) . " re f\n"
            . '0.55 G 0.6 w 0 ' . self::number($stripHeight) . ' m ' . self::number($width) . ' '
            . self::number($stripHeight) . " l S\n"
            . self::textLines($lines, self::MARGIN_FONT_SIZE, self::MARGIN_LINE_HEIGHT, $width, $top)
            . "Q\n";
    }

    /** Linhas centralizadas, de cima para baixo, a partir da linha de base $top. */
    private static function textLines(array $lines, float $size, float $lineHeight, float $width, float $top): string
    {
        $operators = "0.15 g\nBT " . self::FONT . ' ' . self::number($size) . " Tf\n";

        foreach ($lines as $index => $line) {
            $x = max(0.0, ($width - PdfHelvetica::width($line, $size)) / 2);
            $y = $top - $index * $lineHeight;

            $operators .= '1 0 0 1 ' . self::number($x) . ' ' . self::number($y) . ' Tm '
                . PdfHelvetica::literal($line) . " Tj\n";
        }

        return $operators . "ET\n";
    }

    /**
     * Sistema de coordenadas "de leitura" de um retângulo da página:
     * origem no canto inferior esquerdo como o leitor vê a página
     * (considerando /Rotate), eixo x para a direita.
     *
     * @return array{0: array, 1: float, 2: float} [matriz, largura, altura]
     */
    private static function orient(array $box, int $rotate): array
    {
        [$x0, $y0, $x1, $y1] = $box;
        $w = $x1 - $x0;
        $h = $y1 - $y0;

        return match ($rotate) {
            90      => [[0, 1, -1, 0, $x1, $y0], $h, $w],
            180     => [[-1, 0, 0, -1, $x1, $y1], $w, $h],
            270     => [[0, -1, 1, 0, $x0, $y1], $h, $w],
            default => [[1, 0, 0, 1, $x0, $y0], $w, $h],
        };
    }

    /**
     * Envolve o conteúdo original em q/Q (estado gráfico isolado) e
     * acrescenta o aviso, com a fonte nos recursos da página.
     */
    private function appendContent(PdfObjects $objects, array $page, stdClass $pageDict, string $operators, string $fontRef): void
    {
        if ($operators === '') {
            return;
        }

        $contents = $objects->resolve($pageDict->{'/Contents'} ?? null);
        $original = $pageDict->{'/Contents'} ?? null;

        // /Contents: stream único, array de streams ou referência a array.
        $streams = match (true) {
            is_array($contents)              => $contents,
            PdfObjects::isRef($original)     => [$original],
            default                          => [],
        };

        $pageDict->{'/Contents'} = [
            $objects->addStream("q\n"),
            ...$streams,
            $objects->addStream("Q\n" . $operators),
        ];

        // Recursos: cópia própria da página, sem mexer em dicionários
        // compartilhados com outras páginas.
        $resources = PdfObjects::copy($objects->resolve($page['resources']));
        $resources = $resources instanceof stdClass ? $resources : new stdClass();

        $fonts = PdfObjects::copy($objects->resolve($resources->{'/Font'} ?? null));
        $fonts = $fonts instanceof stdClass ? $fonts : new stdClass();

        $fonts->{self::FONT}       = $fontRef;
        $resources->{'/Font'}      = $fonts;
        $pageDict->{'/Resources'}  = $resources;
    }

    /* ------------------------------------------------------------
     * Utilitários
     * ---------------------------------------------------------- */

    /** Retângulo normalizado [x0, y0, x1, y1] ou null. */
    private static function box(mixed $value): ?array
    {
        if (!is_array($value) || count($value) !== 4) {
            return null;
        }

        foreach ($value as $number) {
            if (!is_int($number) && !is_float($number)) {
                return null;
            }
        }

        [$a, $b, $c, $d] = array_map('floatval', $value);

        return [min($a, $c), min($b, $d), max($a, $c), max($b, $d)];
    }

    private static function matrix(array $matrix): string
    {
        return implode(' ', array_map(self::number(...), $matrix));
    }

    private static function number(float|int $value): string
    {
        $formatted = rtrim(rtrim(sprintf('%.3F', $value), '0'), '.');

        return $formatted === '-0' ? '0' : $formatted;
    }
}
