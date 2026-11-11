<?php

declare(strict_types=1);

/**
 * PDFs sintéticos para os testes. Nenhum documento real é usado.
 *
 * As assinaturas têm estrutura de assinatura digital (campo /Sig com
 * /ByteRange e /Contents contendo um CMS de verdade, gerado com um
 * certificado de teste), mas não cobrem os bytes do arquivo — para o
 * PDF Tools só importa a estrutura.
 */
final class Fixtures
{
    public function __construct(private readonly string $directory)
    {
    }

    /**
     * PDF com uma página por item de $pages.
     *
     * Cada página: ['rotate' => 0, 'text' => '...', 'signatures' => [
     *     ['rect' => [x0, y0, x1, y1], 'name' => '...', 'date' => 'D:...',
     *      'cn' => 'NOME:CPF', 'kid' => false, 'hidden' => false],
     * ]]
     */
    public function pdf(string $name, array $pages): string
    {
        $objects   = [];
        $objects[] = null; // 1: catálogo, preenchido no fim
        $objects[] = null; // 2: árvore de páginas
        $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>'; // 3

        $add = static function (string $body) use (&$objects): int {
            $objects[] = $body;

            return count($objects);
        };

        $kids   = [];
        $fields = [];

        foreach ($pages as $index => $page) {
            $text    = $page['text'] ?? 'Pagina ' . ($index + 1);
            $content = "0.85 0.9 1 rg 0 0 595 842 re f 1 0 0 RG 3 w 2 2 591 838 re S\n"
                . "0 g BT /F1 14 Tf 40 760 Td ({$text}) Tj ET\n"
                . "BT /F1 10 Tf 40 6 Td (TEXTO ENCOSTADO NA BORDA INFERIOR) Tj ET";
            $contentId = $add('<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream");

            $pageId = $add('');
            $annots = [];

            foreach ($page['signatures'] ?? [] as $signature) {
                $sigDict = '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached'
                    . ' /ByteRange [0 100 200 300]'
                    . ' /Contents <' . bin2hex($this->cms($signature['cn'] ?? 'TESTE', $signature['icp'] ?? [])) . str_repeat('0', 64) . '>'
                    . (isset($signature['name']) ? ' /Name (' . $signature['name'] . ')' : '')
                    . (isset($signature['date']) ? ' /M (' . $signature['date'] . ')' : '')
                    . ' >>';
                $sigId = $add($sigDict);

                $rect  = implode(' ', $signature['rect'] ?? [0, 0, 0, 0]);
                $flags = ($signature['hidden'] ?? false) ? 6 : 4;
                $ap    = $this->appearance($signature['rect'] ?? [0, 0, 0, 0], $add);

                if ($signature['kid'] ?? false) {
                    $fieldId  = $add('');
                    $widgetId = $add("<< /Type /Annot /Subtype /Widget /Rect [{$rect}] /F {$flags} /P {$pageId} 0 R /Parent {$fieldId} 0 R /AP << /N {$ap} 0 R >> >>");
                    $objects[$fieldId - 1] = "<< /FT /Sig /T (Assinatura{$sigId}) /V {$sigId} 0 R /Kids [{$widgetId} 0 R] >>";
                } else {
                    $widgetId = $add("<< /Type /Annot /Subtype /Widget /FT /Sig /T (Assinatura{$sigId}) /V {$sigId} 0 R /Rect [{$rect}] /F {$flags} /P {$pageId} 0 R /AP << /N {$ap} 0 R >> >>");
                    $fieldId  = $widgetId;
                }

                $annots[] = "{$widgetId} 0 R";
                $fields[] = "{$fieldId} 0 R";
            }

            $rotate = isset($page['rotate']) ? ' /Rotate ' . $page['rotate'] : '';
            $annotsEntry = $annots === [] ? '' : ' /Annots [' . implode(' ', $annots) . ']';

            $objects[$pageId - 1] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842]{$rotate}"
                . " /Contents {$contentId} 0 R /Resources << /Font << /F1 3 0 R >> >>{$annotsEntry} >>";
            $kids[] = "{$pageId} 0 R";
        }

        $acroForm = $fields === []
            ? ''
            : ' /AcroForm << /Fields [' . implode(' ', $fields) . '] /SigFlags 3 >>';

        $objects[0] = "<< /Type /Catalog /Pages 2 0 R{$acroForm} >>";
        $objects[1] = '<< /Type /Pages /Count ' . count($kids) . ' /Kids [' . implode(' ', $kids) . '] >>';

        $path = $this->directory . '/' . $name . '.pdf';
        file_put_contents($path, self::serialize($objects));

        return $path;
    }

    /**
     * PDF "digitalizado": uma imagem JPEG ruidosa (difícil de comprimir)
     * por página, na resolução indicada para uma página A4.
     */
    public function scannedPdf(string $name, int $pages, int $dpi): string
    {
        $width  = (int) round(8.27 * $dpi);
        $height = (int) round(11.69 * $dpi);

        $objects = [null, null];
        $kids    = [];

        for ($page = 0; $page < $pages; $page++) {
            $image = imagecreatetruecolor($width, $height);
            imagefill($image, 0, 0, imagecolorallocate($image, 240, 238, 230));

            for ($i = 0; $i < 600; $i++) {
                $color = imagecolorallocate($image, random_int(0, 255), random_int(0, 255), random_int(0, 255));
                imagefilledellipse($image, random_int(0, $width), random_int(0, $height), random_int(10, 300), random_int(10, 300), $color);
            }

            for ($i = 0; $i < $width * $height / 20; $i++) {
                imagesetpixel($image, random_int(0, $width - 1), random_int(0, $height - 1), random_int(0, 0xFFFFFF));
            }

            ob_start();
            imagejpeg($image, null, 85);
            $jpeg = (string) ob_get_clean();
            imagedestroy($image);

            $imageId = count($objects) + 1;
            $objects[] = "<< /Type /XObject /Subtype /Image /Width {$width} /Height {$height} /ColorSpace /DeviceRGB"
                . ' /BitsPerComponent 8 /Filter /DCTDecode /Length ' . strlen($jpeg) . " >>\nstream\n{$jpeg}\nendstream";

            $content   = "q 595 0 0 842 0 0 cm /Im Do Q";
            $objects[] = '<< /Length ' . strlen($content) . " >>\nstream\n{$content}\nendstream";

            $pageId    = count($objects) + 1;
            $objects[] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents ' . ($imageId + 1)
                . " 0 R /Resources << /XObject << /Im {$imageId} 0 R >> >> >>";
            $kids[] = "{$pageId} 0 R";
        }

        $objects[0] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[1] = '<< /Type /Pages /Count ' . count($kids) . ' /Kids [' . implode(' ', $kids) . '] >>';

        $path = $this->directory . '/' . $name . '.pdf';
        file_put_contents($path, self::serialize($objects));

        return $path;
    }

    /** Aparência visível da assinatura: caixa vermelha com texto. */
    private function appearance(array $rect, callable $add): int
    {
        $width  = max(0, $rect[2] - $rect[0]);
        $height = max(0, $rect[3] - $rect[1]);
        $stream = "1 0.8 0.8 rg 0 0 {$width} {$height} re f 0.8 0 0 RG 2 w 1 1 "
            . max(0, $width - 2) . ' ' . max(0, $height - 2) . " re S\n"
            . "0.6 0 0 rg BT /F1 9 Tf 6 6 Td (ASSINATURA VISIVEL) Tj ET";

        return $add("<< /Type /XObject /Subtype /Form /BBox [0 0 {$width} {$height}]"
            . ' /Resources << /Font << /F1 3 0 R >> >> /Length ' . strlen($stream)
            . " >>\nstream\n{$stream}\nendstream");
    }

    /**
     * CMS (PKCS#7) assinado com um certificado de teste com o CN informado.
     *
     * $icp: campos ICP-Brasil do subjectAltName, ex. [1 => '01011980' . CPF . ...]
     * (2.16.76.1.3.1 do e-CPF) ou [3 => CNPJ] (2.16.76.1.3.3 do e-CNPJ),
     * gravados como OCTET STRING, como nos certificados reais.
     */
    private function cms(string $commonName, array $icp = []): string
    {
        $options = [];
        $config  = null;

        if ($icp !== []) {
            $names = [];

            foreach ($icp as $field => $value) {
                $names[] = "otherName:2.16.76.1.3.{$field};OCT:{$value}";
            }

            $config = tempnam($this->directory, 'openssl-');
            file_put_contents($config, "[req]\ndistinguished_name = dn\n[dn]\n[ext]\nsubjectAltName = " . implode(',', $names) . "\n");
            $options = ['config' => $config, 'x509_extensions' => 'ext'];
        }

        $key  = openssl_pkey_new(['private_key_bits' => 2048]);
        $csr  = openssl_csr_new(['commonName' => $commonName], $key);
        $cert = openssl_csr_sign($csr, null, $key, 1, $options);

        if ($config !== null) {
            unlink($config);
        }

        $in  = tempnam($this->directory, 'cms-in-');
        $out = tempnam($this->directory, 'cms-out-');
        file_put_contents($in, 'conteudo');

        openssl_pkcs7_sign($in, $out, $cert, $key, [], PKCS7_BINARY | PKCS7_DETACHED);

        // S/MIME → DER: o anexo base64 "smime.p7s" é o CMS.
        $smime = (string) file_get_contents($out);
        unlink($in);
        unlink($out);

        if (!preg_match('/filename="?smime\.p7s"?\s*\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $smime, $match)) {
            throw new RuntimeException('Não foi possível gerar o CMS de teste.');
        }

        return base64_decode(preg_replace('/\s+/', '', $match[1]));
    }

    /** @param list<string> $objects */
    public static function serialize(array $objects): string
    {
        $pdf     = "%PDF-1.7\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];

        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1) . " 0 obj\n{$body}\nendobj\n";
        }

        $xref = strlen($pdf);
        $pdf .= 'xref' . "\n0 " . (count($objects) + 1) . "\n0000000000 65535 f \n";

        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        return $pdf . 'trailer' . "\n<< /Size " . (count($objects) + 1)
            . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";
    }
}
