/* -------------------------------------------------------------
 * XcelSync Frontend Logic (Vanilla JS SPA Wrapper)
 * Handles file uploads, column mapping, and chunk progress polling.
 * ------------------------------------------------------------- */

document.addEventListener('DOMContentLoaded', () => {
    // Session State
    let session = {
        id: null,
        filePath: null,
        headers: [],
        columnTypes: {},
        sampleRows: [],
        fileSizeStr: '',
        fileName: ''
    };

    let importStartTime = null;
    let pollInterval = null;

    // DOM Elements
    const stepIndicators = {
        1: document.getElementById('step-indicator-1'),
        2: document.getElementById('step-indicator-2'),
        3: document.getElementById('step-indicator-3'),
        4: document.getElementById('step-indicator-4')
    };

    const sections = {
        1: document.getElementById('step-1'),
        2: document.getElementById('step-2'),
        3: document.getElementById('step-3'),
        4: document.getElementById('step-4')
    };

    // Navigation Buttons
    const btnGotoStep2 = document.getElementById('goto-step-2-btn');
    const btnGotoStep3 = document.getElementById('goto-step-3-btn');
    const btnStartImport = document.getElementById('start-import-btn');
    const btnCancelImport = document.getElementById('cancel-import-btn');
    const btnFinish = document.getElementById('finish-btn');

    const btnBackTo1 = document.getElementById('back-to-step-1');
    const btnBackTo2 = document.getElementById('back-to-step-2');

    // File Upload Elements
    const dropZone = document.getElementById('drop-zone');
    const fileInput = document.getElementById('file-input');
    const filePreviewCard = document.getElementById('file-preview-card');
    const previewFilename = document.getElementById('preview-filename');
    const previewFilesize = document.getElementById('preview-filesize');
    const btnRemoveFile = document.getElementById('remove-file-btn');

    // DB Settings Elements
    const dbCards = document.querySelectorAll('.db-card');
    const sqlitePanel = document.getElementById('sqlite-panel');
    const mysqlPanel = document.getElementById('mysql-panel');

    // Mapping dropdowns
    const mapNameDropdown = document.getElementById('map-name');
    const mapMobileDropdown = document.getElementById('map-mobile');
    const mapDobDropdown = document.getElementById('map-dob');
    const selectAllColumnsCheckbox = document.getElementById('select-all-columns');
    const schemaTableBody = document.querySelector('#schema-table tbody');

    // Progress Dashboard Elements
    const statTotalRows = document.getElementById('stat-total-rows');
    const statProcessedRows = document.getElementById('stat-processed-rows');
    const statImportedRows = document.getElementById('stat-imported-rows');
    const statSkippedRows = document.getElementById('stat-skipped-rows');
    const importProgressBar = document.getElementById('import-progress-bar');
    const progressPercentage = document.getElementById('progress-percentage');
    const importSpeedText = document.getElementById('import-speed');
    const logPanel = document.getElementById('log-panel');
    const ingestStatusText = document.getElementById('ingest-status-text');

    /* ==========================================
     * SECTION WIZARD FLOW CONTROL
     * ========================================== */

    function navigateToStep(stepNum) {
        // Toggle step indicators
        Object.keys(stepIndicators).forEach(num => {
            stepIndicators[num].classList.remove('active', 'completed');
            if (num < stepNum) {
                stepIndicators[num].classList.add('completed');
            } else if (num == stepNum) {
                stepIndicators[num].classList.add('active');
            }
        });

        // Toggle sections
        Object.keys(sections).forEach(num => {
            sections[num].classList.remove('active');
            if (num == stepNum) {
                sections[num].classList.add('active');
            }
        });
    }

    // Step 1 to Step 2
    btnGotoStep2.addEventListener('click', () => {
        navigateToStep(2);
    });

    btnBackTo1.addEventListener('click', () => {
        navigateToStep(1);
    });

    // Step 2 to Step 3
    btnGotoStep3.addEventListener('click', () => {
        const dbType = document.querySelector('input[name="db_type"]:checked').value;
        const tableName = dbType === 'sqlite'
            ? document.getElementById('sqlite-table').value.trim()
            : document.getElementById('mysql-table').value.trim();

        if (!tableName) {
            showToast('Please enter a target database table name', 'error');
            return;
        }

        // Setup drop-down mapping choices based on analyzed Excel headers
        populateMappingSelectors();
        btnStartImport.disabled = false;
        navigateToStep(3);
    });

    btnBackTo2.addEventListener('click', () => {
        navigateToStep(2);
    });

    // Reset workflow
    btnFinish.addEventListener('click', () => {
        // Reset file state
        session = { id: null, filePath: null, headers: [], columnTypes: {}, sampleRows: [], fileSizeStr: '', fileName: '' };
        fileInput.value = '';
        filePreviewCard.style.display = 'none';
        dropZone.style.display = 'block';
        btnGotoStep2.disabled = true;
        btnStartImport.disabled = false;

        // Reset progress counters
        statTotalRows.textContent = '-';
        statProcessedRows.textContent = '0';
        statImportedRows.textContent = '0';
        statSkippedRows.textContent = '0';
        importProgressBar.style.width = '0%';
        progressPercentage.textContent = '0% Completed';
        importSpeedText.textContent = 'Calculating speed...';
        logPanel.innerHTML = '<div class="log-row info">Initializing database tables...</div>';
        ingestStatusText.textContent = 'Uploading file structure and configuring worker thread.';

        btnFinish.style.display = 'none';
        btnCancelImport.style.display = 'inline-flex';

        navigateToStep(1);
    });

    /* ==========================================
     * STEP 1: FILE DRAG & DROP & UPLOAD
     * ========================================== */

    dropZone.addEventListener('click', () => fileInput.click());

    dropZone.addEventListener('dragover', (e) => {
        e.preventDefault();
        dropZone.classList.add('dragover');
    });

    dropZone.addEventListener('dragleave', () => {
        dropZone.classList.remove('dragover');
    });

    dropZone.addEventListener('drop', (e) => {
        e.preventDefault();
        dropZone.classList.remove('dragover');
        if (e.dataTransfer.files.length > 0) {
            handleFileSelection(e.dataTransfer.files[0]);
        }
    });

    fileInput.addEventListener('change', (e) => {
        if (e.target.files.length > 0) {
            handleFileSelection(e.target.files[0]);
        }
    });

    btnRemoveFile.addEventListener('click', (e) => {
        e.stopPropagation();
        fileInput.value = '';
        filePreviewCard.style.display = 'none';
        dropZone.style.display = 'block';
        btnGotoStep2.disabled = true;
        session.filePath = null;
        session.id = null;
    });

    function formatBytes(bytes, decimals = 2) {
        if (bytes === 0) return '0 Bytes';
        const k = 1024;
        const dm = decimals < 0 ? 0 : decimals;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
    }

    function handleFileSelection(file) {
        const ext = file.name.split('.').pop().toLowerCase();
        if (!['xlsx', 'csv', 'ods'].includes(ext)) {
            showToast('Unsupported file type. Please upload XLSX, CSV or ODS.', 'error');
            return;
        }

        const MAX_FILE_SIZE = 500 * 1024 * 1024; // 500MB
        if (file.size > MAX_FILE_SIZE) {
            showToast('File is too large. Maximum allowed size is 500MB.', 'error');
            return;
        }

        session.fileName = file.name;
        session.fileSizeStr = formatBytes(file.size);

        previewFilename.textContent = session.fileName;
        previewFilesize.textContent = session.fileSizeStr;

        dropZone.style.display = 'none';
        filePreviewCard.style.display = 'flex';

        // Trigger file upload and structural analysis
        uploadAndAnalyzeFile(file);
    }

    function uploadAndAnalyzeFile(file) {
        const CHUNK_SIZE = 30 * 1024 * 1024; // 30MB chunks (safe under 40MB server limits)
        const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
        const sessionId = 'session_' + Date.now() + '_' + Math.random().toString(36).substring(2, 9);

        btnGotoStep2.disabled = true;
        showToast('Starting file upload...', 'info');

        function uploadChunk(chunkIndex) {
            const start = chunkIndex * CHUNK_SIZE;
            const end = Math.min(start + CHUNK_SIZE, file.size);
            const chunk = file.slice(start, end);

            const formData = new FormData();
            formData.append('file', chunk);
            formData.append('chunkIndex', chunkIndex);
            formData.append('totalChunks', totalChunks);
            formData.append('sessionId', sessionId);
            formData.append('fileName', file.name);

            // Update progress indicator in the UI
            const progressPct = Math.round((chunkIndex / totalChunks) * 100);
            if (chunkIndex === totalChunks - 1) {
                previewFilesize.textContent = `${session.fileSizeStr} (Analyzing...)`;
            } else {
                previewFilesize.textContent = `${session.fileSizeStr} (Uploading: ${progressPct}%)`;
            }

            fetch('upload.php', {
                method: 'POST',
                body: formData
            })
                .then(res => {
                    if (!res.ok) {
                        throw new Error(`Server error (HTTP ${res.status})`);
                    }
                    return res.json();
                })
                .then(data => {
                    if (data.status === 'chunk_success') {
                        // Upload next chunk
                        uploadChunk(chunkIndex + 1);
                    } else if (data.status === 'success') {
                        // File completed and analyzed successfully
                        previewFilesize.textContent = session.fileSizeStr;
                        session.id = data.session_id;
                        session.filePath = data.file_path;
                        session.headers = data.headers;
                        session.columnTypes = data.column_types;
                        session.sampleRows = data.sample_rows;

                        showToast('Analysis completed. File schema detected.', 'success');
                        btnGotoStep2.disabled = false;
                    } else {
                        throw new Error(data.message || 'Unknown upload response');
                    }
                })
                .catch(err => {
                    console.error(err);
                    previewFilesize.textContent = session.fileSizeStr;
                    showToast('Upload failed: ' + err.message, 'error');
                    btnRemoveFile.click();
                });
        }

        // Trigger upload starting with the first chunk
        uploadChunk(0);
    }

    /* ==========================================
     * STEP 2: DATABASE CONFIG SELECTOR
     * ========================================== */

    dbCards.forEach(card => {
        card.addEventListener('click', () => {
            dbCards.forEach(c => c.classList.remove('active'));
            card.classList.add('active');

            const radio = card.querySelector('input[type="radio"]');
            radio.checked = true;

            if (radio.value === 'sqlite') {
                sqlitePanel.classList.add('active');
                mysqlPanel.classList.remove('active');
            } else {
                sqlitePanel.classList.remove('active');
                mysqlPanel.classList.add('active');
            }
        });
    });

    /* ==========================================
     * STEP 3: MAPPING & SCHEMA TABLE
     * ========================================== */

    function populateMappingSelectors() {
        // Clear old list items
        mapNameDropdown.innerHTML = '<option value="">-- Do not import --</option>';
        mapMobileDropdown.innerHTML = '<option value="">-- Do not import --</option>';
        mapDobDropdown.innerHTML = '<option value="">-- Do not import --</option>';
        schemaTableBody.innerHTML = '';

        // Fill Special Mappings dropdowns
        session.headers.forEach(header => {
            const cleanHeader = escapeHtml(header);

            // Name option
            const optName = document.createElement('option');
            optName.value = header;
            optName.textContent = header;
            mapNameDropdown.appendChild(optName);

            // Mobile option
            const optMobile = document.createElement('option');
            optMobile.value = header;
            optMobile.textContent = header;
            mapMobileDropdown.appendChild(optMobile);

            // DOB option
            const optDob = document.createElement('option');
            optDob.value = header;
            optDob.textContent = header;
            mapDobDropdown.appendChild(optDob);
        });

        // Autoguess special fields based on keyword matching
        session.headers.forEach(header => {
            const lower = header.toLowerCase();
            if (lower.includes('name') || lower === 'fullname' || lower === 'full_name') {
                mapNameDropdown.value = header;
            }
            if (lower.includes('mobile') || lower.includes('phone') || lower.includes('contact') || lower === 'num' || lower.includes('tele')) {
                mapMobileDropdown.value = header;
            }
            if (lower.includes('birth') || lower.includes('dob') || lower.includes('date') || lower.includes('born') || lower.includes('bday')) {
                mapDobDropdown.value = header;
            }
        });

        // Fill Full Schema table rows
        session.headers.forEach((header) => {
            const cleanHeader = escapeHtml(header);
            const detectedType = session.columnTypes[header] || 'TEXT';
            const dbFieldId = `db-field-${cleanHeader.replace(/[^a-zA-Z0-9]/g, '_')}`;
            const typeSelectId = `db-type-${cleanHeader.replace(/[^a-zA-Z0-9]/g, '_')}`;

            const row = document.createElement('tr');
            row.innerHTML = `
                <td>
                    <input type="checkbox" class="col-import-check" data-header="${cleanHeader}" checked>
                </td>
                <td class="excel-header-name">${cleanHeader}</td>
                <td>
                    <input type="text" id="${dbFieldId}" class="db-field-name-input" value="${cleanHeader.replace(/[^a-zA-Z0-9_]/g, '_').toLowerCase()}">
                </td>
                <td>
                    <select id="${typeSelectId}" class="db-type-select">
                        <option value="TEXT" ${detectedType === 'TEXT' ? 'selected' : ''}>TEXT (String)</option>
                        <option value="INTEGER" ${detectedType === 'INTEGER' ? 'selected' : ''}>INTEGER (Numbers)</option>
                        <option value="REAL" ${detectedType === 'REAL' ? 'selected' : ''}>REAL (Float/Decimal)</option>
                        <option value="DATE" ${detectedType === 'DATE' ? 'selected' : ''}>DATE (YYYY-MM-DD)</option>
                    </select>
                </td>
            `;
            schemaTableBody.appendChild(row);
        });

        // Link Select-All checkbox
        selectAllColumnsCheckbox.checked = true;
        selectAllColumnsCheckbox.addEventListener('change', (e) => {
            document.querySelectorAll('.col-import-check').forEach(chk => {
                chk.checked = e.target.checked;
            });
        });
    }

    /* ==========================================
     * STEP 4: TRIGGER & MONITOR INGESTION
     * ========================================== */

    btnStartImport.addEventListener('click', () => {
        const mobileCol = mapMobileDropdown.value;

        // Collect db connection config
        const dbType = document.querySelector('input[name="db_type"]:checked').value;
        let dbConfig = { type: dbType };
        let tableName = '';

        if (dbType === 'sqlite') {
            tableName = document.getElementById('sqlite-table').value.trim();
        } else {
            tableName = document.getElementById('mysql-table').value.trim();
            dbConfig.host = document.getElementById('mysql-host').value.trim();
            dbConfig.port = document.getElementById('mysql-port').value.trim();
            dbConfig.username = document.getElementById('mysql-username').value.trim();
            dbConfig.password = document.getElementById('mysql-password').value;
            dbConfig.dbname = document.getElementById('mysql-dbname').value.trim();
        }

        // Collect mappings
        const mapping = {
            name: mapNameDropdown.value,
            mobile: mobileCol,
            dob: mapDobDropdown.value
        };

        // Collect columns definition
        const columns = {};
        let mappingValidated = true;

        document.querySelectorAll('.col-import-check:checked').forEach(chk => {
            const origHeader = chk.getAttribute('data-header');
            const rowEl = chk.closest('tr');

            const dbFieldInput = rowEl.querySelector('.db-field-name-input');
            const typeSelect = rowEl.querySelector('.db-type-select');

            const dbFieldName = dbFieldInput.value.trim();
            const dbFieldType = typeSelect.value;

            if (!dbFieldName) {
                showToast(`Empty database field name for Excel column: "${origHeader}"`, 'error');
                mappingValidated = false;
                return;
            }

            columns[origHeader] = dbFieldType;
        });

        if (!mappingValidated) return;
        if (Object.keys(columns).length === 0) {
            showToast('Select at least one column to import.', 'error');
            return;
        }

        // Start Ingestion Process
        const payload = {
            session_id: session.id,
            file_path: session.filePath,
            table_name: tableName,
            mapping: mapping,
            columns: columns,
            db_config: dbConfig
        };

        btnStartImport.disabled = true;
        showToast('Initializing ingestion...', 'info');

        fetch('start_import.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Import worker started successfully.', 'success');
                    importStartTime = Date.now();
                    navigateToStep(4);
                    startPollingStatus();
                } else {
                    showToast('Failed to start import: ' + data.message, 'error');
                    btnStartImport.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Network error starting import.', 'error');
                btnStartImport.disabled = false;
            });
    });

    // Cancel Import
    btnCancelImport.addEventListener('click', () => {
        if (!session.id) return;

        btnCancelImport.disabled = true;
        logMessage('Sending cancel request...', 'warning');

        fetch('cancel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ session_id: session.id })
        })
            .then(res => res.json())
            .then(data => {
                if (data.status === 'success') {
                    showToast('Cancellation request sent.', 'warning');
                } else {
                    showToast('Failed to cancel: ' + data.message, 'error');
                    btnCancelImport.disabled = false;
                }
            })
            .catch(err => {
                console.error(err);
                showToast('Error sending cancellation command.', 'error');
                btnCancelImport.disabled = false;
            });
    });

    /* ==========================================
     * POLLING & METRICS DISPLAY
     * ========================================== */

    function startPollingStatus() {
        if (pollInterval) clearInterval(pollInterval);

        pollInterval = setInterval(() => {
            fetch(`status.php?session_id=${session.id}`)
                .then(res => res.json())
                .then(status => {
                    updateProgressUI(status);

                    // If final state, stop polling
                    if (['completed', 'failed', 'cancelled'].includes(status.status)) {
                        clearInterval(pollInterval);
                        handleImportFinished(status);
                    }
                })
                .catch(err => {
                    console.error(err);
                    logMessage('Error fetching progress metrics...', 'danger');
                });
        }, 1000);
    }

    function updateProgressUI(status) {
        // Dynamic status headings
        if (status.status === 'starting') {
            ingestStatusText.textContent = 'Counting lines and preparing data streams...';
        } else if (status.status === 'running') {
            ingestStatusText.textContent = 'Streaming row records into target database...';
        } else if (status.status === 'cancelling') {
            ingestStatusText.textContent = 'Aborting ingestion worker. Safely rolling back database transaction...';
        }

        const total = parseInt(status.total_rows) || 0;
        const processed = parseInt(status.processed_rows) || 0;
        const imported = parseInt(status.imported_rows) || 0;
        const skipped = parseInt(status.skipped_rows) || 0;

        statTotalRows.textContent = total > 0 ? total.toLocaleString() : 'Analyzing...';
        statProcessedRows.textContent = processed.toLocaleString();
        statImportedRows.textContent = imported.toLocaleString();
        statSkippedRows.textContent = skipped.toLocaleString();

        // Calculate progress percentage
        let pct = 0;
        if (total > 0) {
            pct = Math.min(100, Math.round((processed / total) * 100));
            importProgressBar.style.width = `${pct}%`;
            progressPercentage.textContent = `${pct}% Completed`;
        } else {
            importProgressBar.style.width = '0%';
            progressPercentage.textContent = 'Analyzing...';
        }

        // Calculate Ingestion Speed & ETA
        const elapsedSec = (Date.now() - importStartTime) / 1000;
        if (processed > 0 && elapsedSec > 0) {
            const speed = Math.round(processed / elapsedSec);
            importSpeedText.textContent = `${speed.toLocaleString()} rows/sec`;

            if (total > 0) {
                const remainingRows = total - processed;
                const etaSeconds = Math.round(remainingRows / speed);
                if (etaSeconds > 0) {
                    const min = Math.floor(etaSeconds / 60);
                    const sec = etaSeconds % 60;
                    importSpeedText.textContent += ` (ETA: ${min}m ${sec}s)`;
                }
            }
        }

        // Periodically log updates in visual console
        if (processed > 0 && processed % 5000 === 0) {
            logMessage(`Ingested ${processed.toLocaleString()} records... Success: ${imported.toLocaleString()} | Skipped: ${skipped.toLocaleString()}`, 'info');
        }
    }

    function handleImportFinished(status) {
        btnCancelImport.style.display = 'none';
        btnCancelImport.disabled = false;
        btnFinish.style.display = 'inline-flex';

        if (status.status === 'completed') {
            ingestStatusText.innerHTML = '<span class="text-success"><i class="fa-solid fa-circle-check"></i>Completed Successfully!</span>';
            logMessage(`Completed. Total Imported: ${status.imported_rows.toLocaleString()} rows. Total Skipped: ${status.skipped_rows.toLocaleString()} rows.`, 'success');
            showToast('Excel Ingestion Completed!', 'success');
        } else if (status.status === 'failed') {
            ingestStatusText.innerHTML = '<span class="text-danger"><i class="fa-solid fa-triangle-exclamation"></i> Ingestion Terminated with Failures</span>';
            logMessage(`Failure Error: ${status.error_message}`, 'danger');
            showToast('Ingestion execution failed.', 'error');
        } else if (status.status === 'cancelled') {
            ingestStatusText.innerHTML = '<span class="text-warning"><i class="fa-solid fa-ban"></i> Ingestion Aborted & Reverted</span>';
            logMessage('Process cancelled by client command. All database modifications of the final block rolled back.', 'warning');
            showToast('Ingestion cancelled.', 'warning');
        }
    }

    function logMessage(text, type = '') {
        const row = document.createElement('div');
        row.className = `log-row ${type}`;
        row.textContent = `[${new Date().toLocaleTimeString()}] ${text}`;
        logPanel.appendChild(row);
        logPanel.scrollTop = logPanel.scrollHeight;
    }

    /* ==========================================
     * HELPER FUNCTIONS
     * ========================================== */

    function showToast(message, type = 'info') {
        const container = document.getElementById('toast-container');
        const toast = document.createElement('div');
        toast.className = `toast ${type}`;

        let icon = 'fa-circle-info';
        if (type === 'success') icon = 'fa-circle-check';
        if (type === 'error') icon = 'fa-triangle-exclamation';
        if (type === 'warning') icon = 'fa-circle-exclamation';

        toast.innerHTML = `
            <i class="fa-solid ${icon}"></i>
            <span>${escapeHtml(message)}</span>
        `;
        container.appendChild(toast);

        setTimeout(() => {
            toast.style.opacity = '0';
            toast.style.transform = 'translateY(15px)';
            toast.style.transition = 'opacity 0.4s, transform 0.4s';
            setTimeout(() => toast.remove(), 400);
        }, 3500);
    }

    function escapeHtml(str) {
        if (!str) return '';
        return str.toString()
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }
});
