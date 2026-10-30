<?php

declare(strict_types=1);

/**
 * Chaves de API, guardadas como hash SHA-256 num arquivo JSON:
 * {"<cliente>": "<sha256 da chave>"}.
 *
 * As chaves são aleatórias de 256 bits, então um hash simples basta —
 * mesmo que o arquivo vaze, não dá para recuperar a chave.
 * Gerenciadas pelo script bin/api-key.php.
 */
final class PdfApiKeys
{
    public function __construct(
        private readonly string $file
    ) {}

    /**
     * Identifica o cliente pelo cabeçalho "Authorization: Bearer <chave>".
     *
     * @return string Nome do cliente dono da chave
     */
    public function authenticate(): string
    {
        $keys = $this->all();

        if ($keys === []) {
            throw new PdfToolException(
                'api_disabled',
                'A API não está configurada.',
                503
            );
        }

        $token = $this->bearerToken();

        if ($token !== null) {
            $hash = self::hash($token);

            foreach ($keys as $client => $expected) {
                if (hash_equals($expected, $hash)) {
                    return $client;
                }
            }
        }

        throw new PdfToolException(
            'unauthorized',
            'Chave de API ausente ou inválida.',
            401
        );
    }

    /** @return array<string, string> Cliente => hash */
    public function all(): array
    {
        if (!is_file($this->file)) {
            return [];
        }

        $keys = json_decode(
            (string) file_get_contents($this->file),
            true
        );

        if (!is_array($keys)) {
            throw new RuntimeException(
                "Arquivo de chaves de API inválido: {$this->file}"
            );
        }

        return array_filter(
            $keys,
            static fn ($hash, $client): bool =>
                is_string($client) && is_string($hash) && $hash !== '',
            ARRAY_FILTER_USE_BOTH
        );
    }

    /**
     * Cria (ou substitui) a chave de um cliente.
     *
     * @return string A chave em texto — exibida uma única vez
     */
    public function create(string $client): string
    {
        $key  = 'pdft_' . bin2hex(random_bytes(32));
        $keys = $this->all();

        $keys[$client] = self::hash($key);

        $this->save($keys);

        return $key;
    }

    public function remove(string $client): bool
    {
        $keys = $this->all();

        if (!isset($keys[$client])) {
            return false;
        }

        unset($keys[$client]);

        $this->save($keys);

        return true;
    }

    private static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /** @param array<string, string> $keys */
    private function save(array $keys): void
    {
        ksort($keys);

        $json = json_encode(
            $keys,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        ) . "\n";

        if (file_put_contents($this->file, $json, LOCK_EX) === false) {
            throw new RuntimeException(
                "Não foi possível gravar: {$this->file}"
            );
        }
    }

    private function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';

        if (preg_match('/^Bearer\s+(\S+)$/i', (string) $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
