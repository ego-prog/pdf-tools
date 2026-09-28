const fileInput = document.getElementById('fileInput');
const dropzone = document.getElementById('dropzone');
const fileList = document.getElementById('fileList');

const mergeTool = document.getElementById('mergeTool');
const compressTool = document.getElementById('compressTool');

const mergeButton = document.getElementById('mergeButton');
const clearButton = document.getElementById('clearButton');

const compressionOptions = document.getElementById('compressionOptions');
const compressionLevel = document.getElementById('compressionLevel');

const dropzoneTitle = document.getElementById('dropzoneTitle');
const dropzoneText = document.getElementById('dropzoneText');

const message = document.getElementById('message');

let files = [];
let operation = 'merge';

function setOperation(newOperation) {
    operation = newOperation;

    const isMerge = operation === 'merge';

    mergeTool.classList.toggle('active', isMerge);
    compressTool.classList.toggle('active', !isMerge);

    compressionOptions.hidden = isMerge;

    fileInput.multiple = isMerge;

    if (isMerge) {
        dropzoneTitle.textContent = 'Selecione os arquivos PDF';
        dropzoneText.textContent =
            'Clique aqui ou arraste os arquivos para esta área.';
        mergeButton.textContent = 'Unir PDFs';
    } else {
        dropzoneTitle.textContent = 'Selecione um arquivo PDF';
        dropzoneText.textContent =
            'Clique aqui ou arraste um PDF para esta área.';
        mergeButton.textContent = 'Compactar PDF';
    }

    files = [];

    renderFiles();
    clearMessage();
}

mergeTool.addEventListener('click', () => {
    setOperation('merge');
});

compressTool.addEventListener('click', () => {
    setOperation('compress');
});

function showMessage(text, type) {
    message.textContent = text;
    message.className = 'message ' + type;
}

function clearMessage() {
    message.textContent = '';
    message.className = 'message';
}

function addFiles(selectedFiles) {
    if (operation === 'compress') {
        const file = selectedFiles[0];

        if (!file || file.type !== 'application/pdf') {
            showMessage('Selecione um arquivo PDF.', 'error');
            return;
        }

        files = [{
            file: file,
            password: ''
        }];

        renderFiles();
        clearMessage();
        return;
    }

    for (const file of selectedFiles) {
        if (file.type !== 'application/pdf') {
            continue;
        }

        const alreadyExists = files.some(
            existing =>
                existing.file.name === file.name &&
                existing.file.size === file.size &&
                existing.file.lastModified === file.lastModified
        );

        if (!alreadyExists) {
            files.push({
                file: file,
                password: ''
            });
        }
    }

    renderFiles();
    clearMessage();
}

function renderFiles() {
    fileList.innerHTML = '';

    if (files.length === 0) {
        fileList.innerHTML = `
            <div class="empty">
                Nenhum arquivo selecionado.
            </div>
        `;

        mergeButton.disabled = true;
        return;
    }

    files.forEach((item, index) => {
        const element = document.createElement('div');

        element.className = 'file-item';
        element.draggable = operation === 'merge';

        element.innerHTML = `
            ${
                operation === 'merge'
                    ? '<span class="drag-handle">☰</span>'
                    : ''
            }

            <span class="file-name">
                ${escapeHtml(item.file.name)}
            </span>

            ${
                operation === 'merge'
                    ? `
                        <input
                            type="password"
                            class="password"
                            placeholder="Senha (se houver)"
                            value="${escapeHtml(item.password)}">
                    `
                    : ''
            }

            <button
                type="button"
                class="remove"
                title="Remover">
                ×
            </button>
        `;

        const removeButton = element.querySelector('.remove');

        removeButton.addEventListener('click', () => {
            files.splice(index, 1);
            renderFiles();
            clearMessage();
        });

        if (operation === 'merge') {
            const passwordInput =
                element.querySelector('.password');

            passwordInput.addEventListener('input', event => {
                files[index].password = event.target.value;
            });

            element.addEventListener('dragstart', event => {
                event.dataTransfer.setData(
                    'text/plain',
                    String(index)
                );

                element.classList.add('dragging');
            });

            element.addEventListener('dragend', () => {
                element.classList.remove('dragging');
            });

            element.addEventListener('dragover', event => {
                event.preventDefault();
            });

            element.addEventListener('drop', event => {
                event.preventDefault();

                const fromIndex = Number(
                    event.dataTransfer.getData('text/plain')
                );

                if (
                    Number.isNaN(fromIndex) ||
                    fromIndex === index
                ) {
                    return;
                }

                const [movedFile] = files.splice(
                    fromIndex,
                    1
                );

                files.splice(index, 0, movedFile);

                renderFiles();
            });
        }

        fileList.appendChild(element);
    });

    if (operation === 'merge') {
        mergeButton.disabled = files.length < 2;
    } else {
        mergeButton.disabled = files.length !== 1;
    }
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = value;
    return div.innerHTML;
}

fileInput.addEventListener('change', event => {
    addFiles(event.target.files);
    fileInput.value = '';
});

dropzone.addEventListener('dragover', event => {
    event.preventDefault();
    dropzone.classList.add('dragover');
});

dropzone.addEventListener('dragleave', () => {
    dropzone.classList.remove('dragover');
});

dropzone.addEventListener('drop', event => {
    event.preventDefault();
    dropzone.classList.remove('dragover');

    addFiles(event.dataTransfer.files);
});

clearButton.addEventListener('click', () => {
    files = [];
    renderFiles();
    clearMessage();
});

mergeButton.addEventListener('click', async () => {
    if (files.length === 0) {
        return;
    }

    if (operation === 'merge' && files.length < 2) {
        showMessage(
            'É necessário selecionar pelo menos dois PDFs.',
            'error'
        );
        return;
    }

    const formData = new FormData();

    formData.append('operation', operation);

    if (operation === 'merge') {
        for (const item of files) {
            formData.append('pdf[]', item.file);
            formData.append('password[]', item.password);
        }
    } else {
        formData.append('pdf', files[0].file);
        formData.append(
            'level',
            compressionLevel.value
        );
    }

    mergeButton.disabled = true;
    clearButton.disabled = true;

    showMessage(
        operation === 'merge'
            ? 'Processando PDFs...'
            : 'Compactando PDF...',
        'success'
    );

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            const error = await response.text();
            throw new Error(error);
        }

        const blob = await response.blob();

        const url = URL.createObjectURL(blob);

        const link = document.createElement('a');

        link.href = url;
        link.download = 'resultado.pdf';

        document.body.appendChild(link);
        link.click();
        link.remove();

        URL.revokeObjectURL(url);

        showMessage(
            operation === 'merge'
                ? 'PDF criado com sucesso.'
                : 'PDF compactado com sucesso.',
            'success'
        );
    } catch (error) {
        showMessage(
            error.message || 'Erro ao processar o PDF.',
            'error'
        );
    } finally {
        clearButton.disabled = false;

        if (operation === 'merge') {
            mergeButton.disabled = files.length < 2;
        } else {
            mergeButton.disabled = files.length !== 1;
        }
    }
});

setOperation('merge');
