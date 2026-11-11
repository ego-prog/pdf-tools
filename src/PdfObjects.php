<?php

declare(strict_types=1);

/**
 * Objetos de um PDF no formato JSON v2 do qpdf (qpdf --json-output),
 * com registro das alterações para aplicar com qpdf --update-from-json.
 *
 * Valores seguem a convenção do qpdf: dicionários são stdClass com
 * chaves "/Nome", referências são strings "12 0 R", strings do PDF são
 * "u:texto" ou "b:hex", nomes são "/Nome".
 */
final class PdfObjects
{
    private const MAX_DEPTH = 64;

    /** @var array<string, stdClass> "12 0 R" => {"value": ...} ou {"stream": ...} */
    private array $objects = [];

    /** @var array<string, true> */
    private array $changed = [];

    /** @var array<string, string> novos streams: referência => dados */
    private array $newStreams = [];

    private stdClass $header;

    private stdClass $trailer;

    private int $nextId;

    public function __construct(string $json)
    {
        $data = json_decode($json, false, 100000, JSON_THROW_ON_ERROR);

        if (!isset($data->qpdf[0], $data->qpdf[1])) {
            throw new RuntimeException('JSON do qpdf em formato inesperado.');
        }

        $this->header = $data->qpdf[0];

        foreach ((array) $data->qpdf[1] as $key => $object) {
            if ($key === 'trailer') {
                $this->trailer = $object->value;
            } elseif (str_starts_with($key, 'obj:')) {
                $this->objects[substr($key, 4)] = $object;
            }
        }

        if (!isset($this->trailer)) {
            throw new RuntimeException('JSON do qpdf sem trailer.');
        }

        $this->nextId = (int) $this->header->maxobjectid + 1;
    }

    public static function isRef(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d+ \d+ R$/', $value) === 1;
    }

    public function trailer(): stdClass
    {
        return $this->trailer;
    }

    /**
     * Segue referências até um valor direto. Streams retornam o
     * dicionário do stream; referência inexistente retorna null.
     */
    public function resolve(mixed $value): mixed
    {
        for ($depth = 0; self::isRef($value); $depth++) {
            if ($depth > self::MAX_DEPTH || !isset($this->objects[$value])) {
                return null;
            }

            $object = $this->objects[$value];
            $value  = property_exists($object, 'value')
                ? $object->value
                : ($object->stream->dict ?? null);
        }

        return $value;
    }

    /** @return iterable<string, mixed> referência => valor (objetos não-stream) */
    public function values(): iterable
    {
        foreach ($this->objects as $ref => $object) {
            if (property_exists($object, 'value')) {
                yield $ref => $object->value;
            }
        }
    }

    /** Substitui o valor de um objeto existente (não-stream). */
    public function set(string $ref, mixed $value): void
    {
        if (!isset($this->objects[$ref]) || !property_exists($this->objects[$ref], 'value')) {
            throw new RuntimeException("Objeto inexistente ou stream: {$ref}");
        }

        $this->objects[$ref]->value = $value;
        $this->changed[$ref]        = true;
    }

    /** Marca como alterado um objeto cujo valor (stdClass) foi modificado no lugar. */
    public function touch(string $ref): void
    {
        $this->set($ref, $this->objects[$ref]->value ?? null);
    }

    public function add(mixed $value): string
    {
        $ref = ($this->nextId++) . ' 0 R';

        $this->objects[$ref] = (object) ['value' => $value];
        $this->changed[$ref] = true;

        return $ref;
    }

    public function addStream(string $data): string
    {
        $ref = ($this->nextId++) . ' 0 R';

        $this->newStreams[$ref] = $data;

        return $ref;
    }

    /** Cópia profunda de um valor (para não alterar objetos compartilhados). */
    public static function copy(mixed $value): mixed
    {
        return json_decode(json_encode($value, JSON_THROW_ON_ERROR), false, 100000, JSON_THROW_ON_ERROR);
    }

    public function hasChanges(): bool
    {
        return $this->changed !== [] || $this->newStreams !== [];
    }

    /** JSON para qpdf --update-from-json com apenas os objetos alterados. */
    public function updateJson(): string
    {
        $objects = [];

        foreach (array_keys($this->changed) as $ref) {
            $objects['obj:' . $ref] = ['value' => $this->objects[$ref]->value];
        }

        foreach ($this->newStreams as $ref => $data) {
            $objects['obj:' . $ref] = [
                'stream' => ['dict' => new stdClass(), 'data' => base64_encode($data)],
            ];
        }

        $header = clone $this->header;
        $header->maxobjectid = $this->nextId - 1;

        return json_encode(
            ['qpdf' => [$header, $objects]],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        );
    }

    /**
     * Texto de uma string do PDF ("u:..." ou "b:hex") em UTF-8.
     */
    public static function text(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        if (str_starts_with($value, 'u:')) {
            return substr($value, 2);
        }

        if (!str_starts_with($value, 'b:')) {
            return '';
        }

        $bytes = (string) @hex2bin(substr($value, 2));

        if (str_starts_with($bytes, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($bytes, 2), 'UTF-8', 'UTF-16BE');
        }

        return mb_check_encoding($bytes, 'UTF-8')
            ? $bytes
            : (string) mb_convert_encoding($bytes, 'UTF-8', 'ISO-8859-1');
    }

    /** Bytes de uma string do PDF. */
    public static function bytes(mixed $value): string
    {
        if (is_string($value) && str_starts_with($value, 'b:')) {
            return (string) @hex2bin(substr($value, 2));
        }

        return is_string($value) && str_starts_with($value, 'u:') ? substr($value, 2) : '';
    }
}
