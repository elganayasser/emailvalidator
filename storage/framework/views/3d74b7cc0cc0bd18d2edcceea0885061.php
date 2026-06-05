<?php $__env->startSection('title', 'Bulk Email Validation'); ?>
<?php $__env->startSection('nav_bulk', 'active'); ?>

<?php $__env->startSection('content'); ?>
<div class="card-label">// bulk validation</div>
<h1>Validate a <span class="accent">list</span><br>of emails</h1>
<p class="subtitle">Upload a CSV file — one email per row — and download results with Valid / Non Valid / Accept All status.</p>

<div class="card">
    <div class="card-label">// upload csv</div>
    <p style="color:var(--muted); font-size:0.82rem; margin-bottom:1.5rem; line-height:1.6;">
        Your CSV should have an <strong style="color:var(--text)">Emails</strong> column header, or just raw emails in the first column. No other columns required.
    </p>

    <div class="file-drop" id="fileDrop">
        <input type="file" id="csvFileInput" accept=".csv">
        <div class="file-drop-icon">📂</div>
        <div class="file-drop-text">Drop your CSV here or <strong>click to browse</strong></div>
        <div class="file-name-display" id="fileNameDisplay"></div>
    </div>

    <div style="margin-top:1.5rem;">
        <button class="btn btn-primary" id="uploadBtn" disabled style="width:100%">
            Select a file to begin
        </button>
    </div>

    <div class="progress-wrap" id="progressWrap">
        <div class="progress-label">
            <span id="progressMsg">Processing emails...</span>
            <span id="progressPct">0%</span>
        </div>
        <div class="progress-track">
            <div class="progress-bar" id="progressBar"></div>
        </div>
    </div>

    <div class="result-box" id="bulkResult" style="margin-top:1.5rem;">
        <div class="result-status" id="bulkResultStatus"></div>
        <div class="result-detail" id="bulkResultDetail"></div>
    </div>
</div>

<div class="card" style="border-color: rgba(0,229,160,0.1);">
    <div class="card-label">// csv format example</div>
    <div style="background:var(--bg); border-radius:8px; padding:1rem 1.2rem; margin-top:0.5rem; font-size:0.82rem; color:var(--muted); line-height:1.8; border:1px solid var(--border);">
        <span style="color:var(--accent)">Emails</span><br>
        john.doe@company.com<br>
        jane.smith@agency.fr<br>
        contact@brand.co.uk
    </div>
    <p style="color:var(--muted); font-size:0.8rem; margin-top:1rem;">
        Results will be downloaded automatically as <strong style="color:var(--text)">validated_emails.csv</strong> with a Deliverability column added.
    </p>
</div>
<?php $__env->stopSection(); ?>

<?php $__env->startSection('scripts'); ?>
<script>
    const fileInput  = document.getElementById('csvFileInput');
    const fileDrop   = document.getElementById('fileDrop');
    const fileLabel  = document.getElementById('fileNameDisplay');
    const uploadBtn  = document.getElementById('uploadBtn');
    const progressWrap = document.getElementById('progressWrap');
    const progressBar  = document.getElementById('progressBar');
    const progressPct  = document.getElementById('progressPct');
    const progressMsg  = document.getElementById('progressMsg');
    const resultBox    = document.getElementById('bulkResult');

    // File selected
    fileInput.addEventListener('change', () => {
        const file = fileInput.files[0];
        if (file) {
            fileLabel.textContent = '📄 ' + file.name;
            fileLabel.style.display = 'block';
            uploadBtn.disabled = false;
            uploadBtn.textContent = '🚀 Validate & Download Results';
        }
    });

    // Drag & drop visual
    fileDrop.addEventListener('dragover',  e => { e.preventDefault(); fileDrop.classList.add('dragover'); });
    fileDrop.addEventListener('dragleave', () => fileDrop.classList.remove('dragover'));
    fileDrop.addEventListener('drop', e => {
        e.preventDefault();
        fileDrop.classList.remove('dragover');
        if (e.dataTransfer.files[0]) {
            fileInput.files = e.dataTransfer.files;
            fileInput.dispatchEvent(new Event('change'));
        }
    });

    // Upload & process
    uploadBtn.addEventListener('click', () => {
        const file = fileInput.files[0];
        if (!file) return;

        const formData = new FormData();
        formData.append('csvFile', file);
        formData.append('_token', CSRF);

        uploadBtn.disabled = true;
        uploadBtn.innerHTML = '<span class="spinner"></span>Processing...';
        progressWrap.style.display = 'block';
        resultBox.style.display = 'none';
        resultBox.className = 'result-box';

        // Animate fake progress
        let fakeP = 0;
        const ticker = setInterval(() => {
            if (fakeP < 88) {
                fakeP += (Math.random() * 2.5);
                setProgress(Math.min(fakeP, 88));
            }
        }, 400);

        fetch('/check-bulk', {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': CSRF },
            body: formData
        })
        .then(response => {
            if (!response.ok) throw new Error('Server error');
            return response.blob();
        })
        .then(blob => {
            clearInterval(ticker);
            setProgress(100);
            progressMsg.textContent = 'Complete!';

            // Trigger download
            const url  = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href  = url;
            link.download = 'validated_emails.csv';
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);

            document.getElementById('bulkResultStatus').textContent = '✅ Done!';
            document.getElementById('bulkResultDetail').textContent = 'Your validated CSV has been downloaded.';
            resultBox.className = 'result-box valid';
            resultBox.style.display = 'block';
        })
        .catch(() => {
            clearInterval(ticker);
            document.getElementById('bulkResultStatus').textContent = 'Error';
            document.getElementById('bulkResultDetail').textContent = 'Something went wrong. Please check your file and try again.';
            resultBox.className = 'result-box invalid';
            resultBox.style.display = 'block';
        })
        .finally(() => {
            uploadBtn.disabled = false;
            uploadBtn.textContent = '🚀 Validate & Download Results';
        });
    });

    function setProgress(pct) {
        const p = Math.round(pct);
        progressBar.style.width = p + '%';
        progressPct.textContent = p + '%';
    }
</script>
<?php $__env->stopSection(); ?>

<?php echo $__env->make('layout', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH /Users/yasser/Desktop/Clients/SPRichards/Phase 2 Delivrability/Version 3/emailvalidator/resources/views/bulk.blade.php ENDPATH**/ ?>