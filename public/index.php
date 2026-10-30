<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

/* ============================================================
 * API — /api/v1/* (autenticada por chave)
 * ============================================================ */
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

if (str_starts_with($requestPath, '/api/')) {
    PdfApi::handle($requestPath);
    exit;
}

/* ============================================================
 * GET — renderiza a interface
 * ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
?>
    <!DOCTYPE html>
    <html lang="pt-BR">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>PDF Tools</title>

        <link rel="stylesheet" href="css/app.css">
    </head>

    <body>

        <main class="container">
            <h1>PDF Tools</h1>
            <p class="subtitle">Ferramentas locais e temporárias para PDFs.</p>

            <section class="panel">

                <div class="tools">
                    <button
                        type="button"
                        class="tool-button active"
                        id="mergeTool">
                        Unir PDFs
                    </button>

                    <button
                        type="button"
                        class="tool-button"
                        id="compressTool">
                        Compactar PDF
                    </button>

                    <button
                        type="button"
                        class="tool-button"
                        id="removeTool">
                        Remover senha
                    </button>
                </div>

                <label class="dropzone" id="dropzone">
                    <input
                        type="file"
                        id="fileInput"
                        accept="application/pdf,.pdf"
                        multiple>

                    <strong id="dropzoneTitle">Selecione os arquivos PDF</strong>
                    <span id="dropzoneText">
                        Clique aqui ou arraste os arquivos para esta área.
                    </span>
                </label>

                <div
                    class="compression-options"
                    id="compressionOptions"
                    hidden>

                    <label for="compressionLevel">
                        Nível de compactação
                    </label>

                    <select id="compressionLevel">
                        <option value="baixa">Baixa</option>
                        <option value="media" selected>Média</option>
                        <option value="alta">Alta</option>
                    </select>

                </div>

                <div
                    class="single-password-options"
                    id="singlePasswordOptions"
                    hidden>

                    <label for="singlePassword">
                        Senha do PDF (se houver)
                    </label>

                    <input
                        type="password"
                        id="singlePassword"
                        placeholder="Senha">

                </div>

                <div class="file-list" id="fileList">
                    <div class="empty">
                        Nenhum arquivo selecionado.
                    </div>
                </div>

                <div class="actions">

                    <button
                        type="button"
                        class="primary"
                        id="mergeButton"
                        disabled>
                        Unir PDFs
                    </button>

                    <button
                        type="button"
                        class="secondary"
                        id="clearButton">
                        Limpar
                    </button>

                </div>

                <div id="message" class="message"></div>

            </section>
        </main>

        <script src="js/app.js"></script>

    </body>

    </html>
<?php

    exit;
}

/* ============================================================
 * POST — processamento pela interface
 *
 * Toda resposta de erro é JSON: {"error": "<código>", "message": ...}.
 * ============================================================ */
PdfController::respond(static function (): void {
    PdfController::assertMethod('POST');
    PdfController::process(PdfRequest::string('operation'));
});
