const fileInput = document.getElementById('fileInput');
const dropzone = document.getElementById('dropzone');
const fileList = document.getElementById('fileList');

const mergeTool = document.getElementById('mergeTool');
const compressTool = document.getElementById('compressTool');
const removeTool = document.getElementById('removeTool');

const mergeButton = document.getElementById('mergeButton');
const clearButton = document.getElementById('clearButton');

const compressionOptions = document.getElementById('compressionOptions');
const compressionLevel = document.getElementById('compressionLevel');

const sizeOptions = document.getElementById('sizeOptions');
const maxSize = document.getElementById('maxSize');

const singlePasswordOptions = document.getElementById('singlePasswordOptions');
const singlePassword = document.getElementById('singlePassword');

const dropzoneTitle = document.getElementById('dropzoneTitle');
const dropzoneText = document.getElementById('dropzoneText');

const message = document.getElementById('message');

let files = [];
let operation = 'merge';

/* ------------------------------------------------------------
 * Alternância entre ferramentas
 * ---------------------------------------------------------- */
function setOperation(newOperation) {
    operation = newOperation;

    const isMerge = operation === 'merge';
    const isCompress = operation === 'compress';
    const isRemove = operation === 'remove_password';

    mergeTool.classList.toggle('active', isMerge);
    compressTool.classList.toggle('active', isCompress);
    removeTool.classList.toggle('active', isRemove);

    compressionOptions.hidden = !isCompress;
    sizeOptions.hidden = !isMerge;
    singlePasswordOptions.hidden = isMerge;

    fileInput.multiple = isMerge;

    if (isMerge) {
        dropzoneTitle.textContent = 'Selecione os arquivos PDF';
        dropzoneText.textContent =
            'Clique aqui ou arraste os arquivos para esta área.';
        mergeButton.textContent = 'Unir PDFs';
    } else if (isCompress) {
        dropzoneTitle.textContent = 'Selecione um arquivo PDF';
        dropzoneText.textContent =
            'Clique aqui ou arraste um PDF para esta área.';
        mergeButton.textContent = 'Compactar PDF';
    } else {
        dropzoneTitle.textContent = 'Selecione um arquivo PDF protegido';
        dropzoneText.textContent =
            'Clique aqui ou arraste um PDF para esta área.';
        mergeButton.textContent = 'Remover senha';
    }

    singlePassword.value = '';
    files = [];

    renderFiles();
    clearMessage();
}

mergeTool.addEventListener('click', () => setOperation('merge'));
compressTool.addEventListener('click', () => setOperation('compress'));
removeTool.addEventListener('click', () => setOperation('remove_password'));

/* ------------------------------------------------------------
 * Mensagens
 * ---------------------------------------------------------- */
function showMessage(text, type) {
    message.textContent = text;
    message.className = 'message ' + type;
}

function clearMessage() {
    message.textContent = '';
    message.className = 'message';
}

/* ------------------------------------------------------------
 * Adicionar arquivos
 * ---------------------------------------------------------- */
function addFiles(selectedFiles) {
    if (operation !== 'merge') {
        const file = selectedFiles[0];

        if (!file || file.type !== 'application/pdf') {
            showMessage('Selecione um arquivo PDF.', 'error');
            return;
        }

        files = [{ file: file, password: '' }];
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
            files.push({ file: file, password: '' });
        }
    }

    renderFiles();
    clearMessage();
}

/* ------------------------------------------------------------
 * Renderização da lista
 * ---------------------------------------------------------- */
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

            <span class="file-name"></span>

            ${
                operation === 'merge'
                    ? `<input
                        type="password"
                        class="password"
                        placeholder="Senha (se houver)">`
                    : ''
            }

            <button
                type="button"
                class="remove"
                title="Remover">
                ×
            </button>
        `;

        // textContent evita injeção de HTML no nome do arquivo.
        element.querySelector('.file-name').textContent = item.file.name;

        element.querySelector('.remove').addEventListener('click', () => {
            files.splice(index, 1);
            renderFiles();
            clearMessage();
        });

        if (operation === 'merge') {
            const passwordInput = element.querySelector('.password');

            // Atribuição programática: evita quebra de atributo quando a
            // senha contém aspas (" ou ').
            passwordInput.value = item.password;

            passwordInput.addEventListener('input', event => {
                files[index].password = event.target.value;
            });

            element.addEventListener('dragstart', event => {
                event.dataTransfer.setData('text/plain', String(index));
                event.dataTransfer.effectAllowed = 'move';
                element.classList.add('dragging');
            });

            element.addEventListener('dragend', () => {
                element.classList.remove('dragging');
            });

            element.addEventListener('dragover', event => {
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
            });

            element.addEventListener('drop', event => {
                event.preventDefault();

                const raw = event.dataTransfer.getData('text/plain');

                if (raw === '') {
                    return;
                }

                const fromIndex = Number(raw);

                if (
                    !Number.isInteger(fromIndex) ||
                    fromIndex < 0 ||
                    fromIndex >= files.length ||
                    fromIndex === index
                ) {
                    return;
                }

                const [movedFile] = files.splice(fromIndex, 1);
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

/* ------------------------------------------------------------
 * Destaques de "senha necessária"
 * ---------------------------------------------------------- */
function highlightPasswordRequired(indices) {
    clearPasswordHighlights();

    indices.forEach(index => {
        const element = fileList.children[index];

        if (!element) {
            return;
        }

        element.classList.add('needs-password');

        const passwordInput = element.querySelector('.password');

        if (passwordInput) {
            passwordInput.classList.add('required');
        }
    });

    const firstInput = fileList.querySelector(
        '.file-item.needs-password .password'
    );

    if (firstInput) {
        firstInput.focus();
    }
}

function highlightSinglePasswordRequired() {
    singlePassword.classList.add('required');
    singlePassword.focus();
}

function clearPasswordHighlights() {
    fileList.querySelectorAll('.needs-password').forEach(el => {
        el.classList.remove('needs-password');
    });

    fileList.querySelectorAll('.password.required').forEach(el => {
        el.classList.remove('required');
    });

    singlePassword.classList.remove('required');
}

/* ------------------------------------------------------------
 * Drag & drop no dropzone
 * ---------------------------------------------------------- */
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
    singlePassword.value = '';
    renderFiles();
    clearMessage();
    clearPasswordHighlights();
});

/* ------------------------------------------------------------
 * O que o servidor fez além de unir (cabeçalhos da resposta)
 * ---------------------------------------------------------- */
function resultNotes(response) {
    const notes = [];

    const signed = (response.headers.get('X-Pdf-Signed-Documents') || '')
        .split(',')
        .filter(value => value !== '');

    if (signed.length === 1) {
        notes.push(
            '1 documento tinha assinatura digital: ela foi substituída por ' +
            'um aviso, pois não é válida na cópia.'
        );
    } else if (signed.length > 1) {
        notes.push(
            `${signed.length} documentos tinham assinatura digital: elas ` +
            'foram substituídas por um aviso, pois não são válidas na cópia.'
        );
    }

    const dpi = response.headers.get('X-Pdf-Compression-Dpi');

    if (dpi) {
        notes.push(`Compactado a ${dpi} dpi para caber no tamanho máximo.`);
    }

    return notes;
}

/* ------------------------------------------------------------
 * Envio
 * ---------------------------------------------------------- */
mergeButton.addEventListener('click', async () => {
    if (files.length === 0) {
        return;
    }

    if (operation === 'merge' && files.length < 2) {
        showMessage('É necessário selecionar pelo menos dois PDFs.', 'error');
        return;
    }

    const formData = new FormData();

    formData.append('operation', operation);

    if (operation === 'merge') {
        for (const item of files) {
            formData.append('pdf[]', item.file);
            formData.append('password[]', item.password);
        }

        if (maxSize.value.trim() !== '') {
            const megabytes = Number(maxSize.value.replace(',', '.'));

            if (!Number.isFinite(megabytes) || megabytes <= 0) {
                showMessage('Informe um tamanho máximo maior que zero.', 'error');
                return;
            }

            formData.append('max_bytes', String(Math.floor(megabytes * 1024 * 1024)));
        }
    } else {
        formData.append('pdf', files[0].file);
        formData.append('password', singlePassword.value);

        if (operation === 'compress') {
            formData.append('level', compressionLevel.value);
        }
    }

    mergeButton.disabled = true;
    clearButton.disabled = true;

    const labels = {
        merge: 'Processando PDFs...',
        compress: 'Compactando PDF...',
        remove_password: 'Removendo senha...',
    };

    showMessage(labels[operation], 'info');

    try {
        const response = await fetch('', {
            method: 'POST',
            body: formData
        });

        if (!response.ok) {
            const contentType = response.headers.get('content-type') || '';

            if (contentType.includes('application/json')) {
                const data = await response.json();

                if (data.error === 'password_required') {
                    if (operation === 'merge') {
                        highlightPasswordRequired(data.indices || []);
                        showMessage(
                            'Os arquivos destacados precisam de senha. ' +
                            'Preencha os campos e tente novamente.',
                            'error'
                        );
                    } else {
                        highlightSinglePasswordRequired();
                        showMessage(
                            'Este PDF precisa de senha. ' +
                            'Informe a senha e tente novamente.',
                            'error'
                        );
                    }
                    return;
                }

                if (data.error === 'invalid_password') {
                    if (operation === 'merge') {
                        highlightPasswordRequired(data.indices || []);
                    } else {
                        highlightSinglePasswordRequired();
                    }

                    showMessage(data.message, 'error');
                    return;
                }

                throw new Error(data.message || 'Erro desconhecido.');
            }

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

        const successLabels = {
            merge: 'PDF criado com sucesso.',
            compress: 'PDF compactado com sucesso.',
            remove_password: 'Senha removida com sucesso.',
        };

        showMessage(
            [successLabels[operation], ...resultNotes(response)].join(' '),
            'success'
        );
        clearPasswordHighlights();
    } catch (error) {
        showMessage(error.message || 'Erro ao processar o PDF.', 'error');
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