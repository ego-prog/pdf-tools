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
| `max_bytes` | texto | opcional; tamanho máximo do resultado, em bytes. Sem ele, nada é compactado |

**Tamanho máximo.** Se o PDF unido passar de `max_bytes`, ele é compactado
com resolução cada vez menor — 150, 125, 100, 85 e 72 dpi — até caber. 72 dpi
é o piso: se nem assim couber, a resposta é `422 size_limit_exceeded`, com
`max_bytes` e `smallest_bytes` (o menor tamanho alcançado). Um arquivo acima
do limite nunca é entregue.

**Assinaturas digitais.** Unir reescreve o PDF, e a assinatura digital
deixa de ser válida. Por isso, em cada PDF assinado o campo de assinatura é
removido e entra um aviso, com data, nome e CPF (ou CNPJ) tirados da
própria assinatura e do certificado:

> Assinado digitalmente no original em 12/03/2026 por Fulano de Tal (CPF 123.456.789-01).

Partes que a assinatura não informa são omitidas. Com várias assinaturas:
"Assinado digitalmente no original: em 12/03/2026 por A (CPF …); em
13/03/2026 por B (CPF …)."

O aviso fica sobre o local da assinatura, quando ela era visível. Nos demais
casos, fica numa faixa acrescentada à borda inferior de cada página do
documento (a página cresce alguns milímetros; nada do conteúdo é coberto).
O arquivo enviado não é alterado.

**Cabeçalhos da resposta:**

| Cabeçalho | Exemplo | |
|---|---|---|
| `X-Pdf-Signed-Documents` | `0,2` | posições (base 0) dos PDFs que tinham assinatura e receberam o aviso; vazio se nenhum |
| `X-Pdf-Compression-Dpi` | `100` | resolução usada para caber em `max_bytes`; ausente se não foi preciso compactar |

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
| 422 | `size_limit_exceeded` | merge não coube em `max_bytes` nem a 72 dpi (`max_bytes`, `smallest_bytes`) |
| 500 | `internal_error` | erro inesperado (detalhes só no log do servidor) |
| 503 | `api_disabled` | nenhuma chave de API cadastrada |
| 504 | `timeout` | processamento excedeu o tempo limite (120 s por padrão) |

## Limites

- 100 MB por arquivo; 110 MB por requisição; até 20 arquivos.
- Senhas não podem conter quebras de linha.

## Nada fica gravado

Uploads, arquivos intermediários e o resultado ficam num diretório em
memória (`/dev/shm/pdf-tools`) e são apagados ao fim de cada requisição. O
nginx do PDF Tools não grava a resposta em arquivo temporário
(`fastcgi_max_temp_file_size 0`).

Quem chama a API deve manter a mesma regra: repassar o PDF ao navegador
conforme ele chega, sem salvar em arquivo nem acumular em memória, como no
exemplo abaixo.

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

Une os documentos e envia o PDF direto ao navegador, à medida que chega,
sem gravar em disco. A chave fica no `.env` (`PDF_TOOLS_KEY`), lida por
`$_ENV`, como as demais configurações do Tiro a Tiro.

```php
/**
 * @param string[] $arquivos caminhos dos PDFs, na ordem desejada
 */
function pdfToolsMergeParaNavegador(array $arquivos, ?int $maxBytes, string $nomeDownload): void
{
    $campos = [];

    foreach (array_values($arquivos) as $i => $arquivo) {
        $campos["pdf[{$i}]"]      = new CURLFile($arquivo, 'application/pdf');
        $campos["password[{$i}]"] = '';
    }

    if ($maxBytes !== null) {
        $campos['max_bytes'] = (string) $maxBytes;
    }

    $status   = 0;
    $erroJson = '';

    $ch = curl_init('http://127.0.0.1:8081/api/v1/merge');

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $campos,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 330,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . ($_ENV['PDF_TOOLS_KEY'] ?? '')],

        // Status e cabeçalhos chegam antes do corpo.
        CURLOPT_HEADERFUNCTION => function ($ch, string $linha) use (&$status): int {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $linha, $m)) {
                $status = (int) $m[1];
            }

            return strlen($linha);
        },

        // Sucesso: cada pedaço do PDF vai direto ao navegador.
        // Erro: o corpo é um JSON pequeno, guardado para a mensagem.
        CURLOPT_WRITEFUNCTION => function ($ch, string $pedaco) use (&$status, &$erroJson, $nomeDownload): int {
            if ($status !== 200) {
                $erroJson .= $pedaco;

                return strlen($pedaco);
            }

            if (!headers_sent()) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: inline; filename="' . $nomeDownload . '"');
                header('Cache-Control: no-store');
            }

            echo $pedaco;
            flush();

            return strlen($pedaco);
        },
    ]);

    // Sem buffer de saída do PHP (o guard.php usa ob_start).
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    $ok = curl_exec($ch);
    curl_close($ch);

    if ($ok === false && $status === 0) {
        throw new RuntimeException('PDF Tools indisponível.');
    }

    if ($status !== 200) {
        $erro = json_decode($erroJson, true) ?? [];
        throw new RuntimeException($erro['message'] ?? "Erro HTTP {$status}");
    }
}
```

O nginx do Tiro a Tiro também precisa repassar sem arquivo temporário na
rota que chama essa função (`fastcgi_max_temp_file_size 0;`) e esperar o
tempo da compactação (`fastcgi_read_timeout 330s;`).
