@extends('layout')
@section('title', 'Single Email Check')
@section('nav_single', 'active')

@section('content')
<div class="card-label">// single check</div>
<h1>Is this email<br><span class="accent">reachable?</span></h1>
<p class="subtitle">Enter any email address — we'll run a live SMTP handshake to verify it exists on the mail server.</p>

<div class="card">
    <div class="input-row">
        <input type="email" id="emailInput" placeholder="john.doe@company.com" autocomplete="off">
        <button class="btn btn-primary" id="checkBtn">Check</button>
    </div>

    <div class="result-box" id="resultBox">
        <div class="result-email" id="resultEmail"></div>
        <div class="result-status" id="resultStatus"></div>
        <div class="result-detail" id="resultDetail"></div>
    </div>
</div>

<div class="card" style="border-color: rgba(0,229,160,0.1);">
    <div class="card-label">// how it works</div>
    <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:1.5rem; margin-top:0.5rem;">
        <div>
            <div style="color:var(--accent); font-size:0.8rem; margin-bottom:0.4rem;">01 — DNS Lookup</div>
            <div style="color:var(--muted); font-size:0.82rem; line-height:1.6;">Resolves MX records for the domain to find the mail server.</div>
        </div>
        <div>
            <div style="color:var(--accent); font-size:0.8rem; margin-bottom:0.4rem;">02 — Decoy Check</div>
            <div style="color:var(--muted); font-size:0.82rem; line-height:1.6;">Tests a fake address to detect catch-all servers.</div>
        </div>
        <div>
            <div style="color:var(--accent); font-size:0.8rem; margin-bottom:0.4rem;">03 — SMTP Verify</div>
            <div style="color:var(--muted); font-size:0.82rem; line-height:1.6;">Sends RCPT TO command and reads the server's 250/5xx response.</div>
        </div>
    </div>
</div>
@endsection

@section('scripts')
<script>
    const btn   = document.getElementById('checkBtn');
    const input = document.getElementById('emailInput');
    const box   = document.getElementById('resultBox');

    function check() {
        const email = input.value.trim();
        if (!email) return;

        btn.disabled = true;
        btn.innerHTML = '<span class="spinner"></span>Checking...';
        box.style.display = 'none';
        box.className = 'result-box';

        fetch('/check-single', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF },
            body: JSON.stringify({ email })
        })
        .then(r => r.json())
        .then(data => {
            document.getElementById('resultEmail').textContent = data.email;
            document.getElementById('resultStatus').textContent = data.status;
            document.getElementById('resultDetail').textContent = data.detail;

            const cls = data.status === 'Valid' ? 'valid'
                      : data.status === 'Accept All' ? 'acceptall'
                      : 'invalid';

            box.className = 'result-box ' + cls;
            box.style.display = 'block';
        })
        .catch(() => {
            document.getElementById('resultEmail').textContent = '';
            document.getElementById('resultStatus').textContent = 'Error';
            document.getElementById('resultDetail').textContent = 'Something went wrong. Please try again.';
            box.className = 'result-box invalid';
            box.style.display = 'block';
        })
        .finally(() => {
            btn.disabled = false;
            btn.textContent = 'Check';
        });
    }

    btn.addEventListener('click', check);
    input.addEventListener('keydown', e => { if (e.key === 'Enter') check(); });
</script>
@endsection
