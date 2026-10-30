# PDF Tools — API v1

Mesmas operações da interface (unir, compactar e remover senha), para
integração com outros sistemas, como o Tiro a Tiro.

## Endereço

| Origem | URL base |
|---|---|
| No próprio servidor (recomendado) | `http://127.0.0.1:8081/api/v1/` |
| Pelo proxy do Tiro a Tiro | `http://tiroserver.local/pdf-tools/api/v1/` |

Chame a API **a partir do servidor** (back-end), nunca do navegador:
a chave não pode ficar exposta no JavaScript.

## Autenticação

Toda requisição leva o cabeçalho:

```
Authorization: Bearer <chave>
```

Gerenciamento das chaves (no servidor, na pasta do projeto):

```bash
php bin/api-key.php add tiroatiro      # cria/substitui; mostra a chave uma única vez
php bin/api-key.php list
php bin/api-key.php remove tiroatiro   # revoga
```

As chaves ficam em `config/api-keys.json` (fora do git), apenas como
hash SHA-256. Sem nenhuma chave cadastrada, a API responde `503 api_disabled`.

## Rotas

Todas as operações recebem `multipart/form-data`.

### `GET /health`

Verifica a chave. Resposta: `{"status": "ok", "client": "tiroatiro"}`.

### `POST /merge`

Une os PDFs na ordem enviada (mínimo 2).

| Campo | Tipo | |
|---|---|---|
| `pdf[]` | arquivo | um por PDF |
| `password[]` | texto | opcional; na mesma posição do PDF correspondente (vazio se não houver) |

### `POST /compress`

| Campo | Tipo | |
|---|---|---|
| `pdf` | arquivo | |
| `level` | texto | `baixa` (150 dpi), `media` (100 dpi, padrão) ou `alta` (72 dpi) |
| `password` | texto | opcional; só se o PDF exigir senha para abrir |

### `POST /remove-password`

| Campo | Tipo | |
|---|---|---|
| `pdf` | arquivo | |
| `password` | texto | senha de abertura; dispensável se o PDF tiver apenas senha de proprietário (restrições de impressão/cópia) |

## Respostas

**Sucesso:** `200`, `Content-Type: application/pdf`, corpo = o PDF.

**Erro:** sempre JSON, com um código estável em `error`:

```json
{"error": "invalid_password", "message": "A senha informada está incorreta.", "indices": [1]}
```

`message` pode ser exibida ao usuário. `indices`, quando presente, aponta
as posições dos arquivos com problema (base 0, na ordem de `pdf[]`).

| HTTP | `error` | Significado |
|---|---|---|
| 400 | `invalid_request` | campo ausente ou com tipo errado, número de arquivos inválido, nível inválido |
| 400 | `invalid_pdf` | o arquivo enviado não é PDF |
| 400 | `upload_failed` | falha no envio do arquivo |
| 400 | `no_password` | remove-password em PDF sem criptografia |
| 401 | `unauthorized` | chave ausente ou inválida |
| 404 | `not_found` | rota inexistente |
| 405 | `method_not_allowed` | método HTTP errado |
| 413 | `file_too_large` | arquivo acima de 100 MB ou requisição acima do limite do servidor |
| 422 | `password_required` | PDF exige senha e nenhuma foi enviada (`indices`) |
| 422 | `invalid_password` | senha incorreta (`indices`) |
| 422 | `invalid_pdf` | PDF corrompido ou ilegível (`indices`) |
| 500 | `internal_error` | erro inesperado (detalhes só no log do servidor) |
| 503 | `api_disabled` | nenhuma chave de API cadastrada |
| 504 | `timeout` | processamento excedeu o tempo limite (120 s por padrão) |

## Limites

- 100 MB por arquivo; 110 MB por requisição; até 20 arquivos.
- Senhas não podem conter quebras de linha.

## Exemplos

### curl

```bash
curl -f -o unido.pdf \
  -H "Authorization: Bearer $PDF_TOOLS_KEY" \
  -F "pdf[]=@a.pdf" -F "password[]=" \
  -F "pdf[]=@b.pdf" -F "password[]=senha-do-b" \
  http://127.0.0.1:8081/api/v1/merge
```

### PHP (Tiro a Tiro)

```php
function pdfToolsCompress(string $arquivo, string $nivel = 'media'): string
{
    $ch = curl_init('http://127.0.0.1:8081/api/v1/compress');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . getenv('PDF_TOOLS_KEY')],
        CURLOPT_POSTFIELDS     => [
            'pdf'   => new CURLFile($arquivo, 'application/pdf'),
            'level' => $nivel,
        ],
    ]);

    $corpo  = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($corpo === false) {
        throw new RuntimeException('PDF Tools indisponível.');
    }

    if ($status !== 200) {
        $erro = json_decode($corpo, true) ?? [];
        throw new RuntimeException($erro['message'] ?? "Erro HTTP {$status}");
    }

    return $corpo; // bytes do PDF
}
```
