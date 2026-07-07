<?php
require __DIR__.'/bootstrap.php';

$id = $_GET['id'] ?? '';
$report = getReport($dbPath);
$session = $id && $report ? $report->getSession($id) : null;

if (! $session) {
    http_response_code(404);
    $errorMsg = $id ? 'Session not found: '.htmlspecialchars($id) : 'No session ID provided.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Session <?= htmlspecialchars($id) ?> · phpkaiharness</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#080a12;--bg-card:#0e1120;--bg-card2:#131628;--bg-input:#0a0c18;
  --border:rgba(124,92,252,.18);--border-h:rgba(124,92,252,.45);
  --primary:#7c5cfc;--primary-g:rgba(124,92,252,.25);
  --cyan:#22d3ee;--cyan-g:rgba(34,211,238,.18);
  --green:#10b981;--yellow:#f59e0b;--red:#ef4444;--blue:#3b82f6;
  --text:#e2e8f0;--text-dim:#94a3b8;--text-muted:#475569;
  --radius:12px;--font-mono:'JetBrains Mono',monospace;
}
body{font-family:'Inter',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;line-height:1.6}

header{
  background:rgba(8,10,18,.92);border-bottom:1px solid var(--border);
  backdrop-filter:blur(12px);position:sticky;top:0;z-index:100;
}
.header-inner{max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;padding:14px 24px;gap:12px}
.logo{display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit}
.logo-icon{font-size:20px}
.logo-name{font-size:16px;font-weight:700;background:linear-gradient(135deg,var(--primary),var(--cyan));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text}
.btn{padding:7px 14px;border-radius:8px;border:none;font-size:13px;font-weight:500;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.btn-ghost{background:transparent;color:var(--text-dim);border:1px solid var(--border)}
.btn-ghost:hover{border-color:var(--primary);color:var(--primary)}

.main{max-width:1100px;margin:0 auto;padding:28px 24px 60px}

/* ── Session header ──────────────────────────────────────────────────────── */
.session-header{
  background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);
  padding:24px;margin-bottom:24px;position:relative;overflow:hidden;
}
.session-header::before{
  content:'';position:absolute;top:0;left:0;right:0;height:3px;
  background:linear-gradient(90deg,var(--primary),var(--cyan));
}
.sh-top{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px}
.sh-id{font-family:var(--font-mono);font-size:12px;color:var(--text-muted)}
.sh-meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
.badge{display:inline-block;font-size:10px;font-weight:600;letter-spacing:.05em;padding:3px 9px;border-radius:20px;text-transform:uppercase;white-space:nowrap}
.badge-green {background:rgba(16,185,129,.15);color:var(--green);border:1px solid rgba(16,185,129,.3)}
.badge-yellow{background:rgba(245,158,11,.15);color:var(--yellow);border:1px solid rgba(245,158,11,.3)}
.badge-cyan  {background:var(--cyan-g);color:var(--cyan);border:1px solid rgba(34,211,238,.3)}
.badge-blue  {background:rgba(59,130,246,.15);color:var(--blue);border:1px solid rgba(59,130,246,.3)}
.badge-purple{background:var(--primary-g);color:var(--primary);border:1px solid var(--border-h)}
.stat-chip{
  display:flex;flex-direction:column;align-items:center;
  background:var(--bg-card2);border:1px solid var(--border);border-radius:8px;
  padding:10px 16px;min-width:100px;
}
.chip-value{font-size:18px;font-weight:700;line-height:1.2}
.chip-label{font-size:10px;color:var(--text-muted);text-transform:uppercase;letter-spacing:.06em;margin-top:2px}
.chips-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
.sh-prompt{
  background:var(--bg-card2);border:1px solid var(--border);border-radius:8px;
  padding:12px 16px;font-size:13px;margin-bottom:12px;
}
.sh-response{
  background:var(--bg-input);border:1px solid var(--border);border-radius:8px;
  padding:14px 16px;font-size:13px;white-space:pre-wrap;word-break:break-word;
  max-height:220px;overflow-y:auto;color:var(--text);line-height:1.7;
}

/* ── Timeline ────────────────────────────────────────────────────────────── */
.timeline-header{font-size:14px;font-weight:600;margin-bottom:16px;display:flex;align-items:center;gap:8px}
.timeline{display:flex;flex-direction:column;gap:12px}

.step-card{
  background:var(--bg-card);border:1px solid var(--border);border-radius:var(--radius);
  overflow:hidden;transition:border-color .2s;
}
.step-card:hover{border-color:var(--border-h)}

.step-header{
  display:flex;align-items:center;gap:12px;padding:14px 18px;
  cursor:pointer;user-select:none;
}
.step-num{
  width:28px;height:28px;border-radius:50%;
  background:var(--bg-card2);border:1px solid var(--border);
  display:flex;align-items:center;justify-content:center;
  font-size:11px;font-weight:700;color:var(--text-dim);flex-shrink:0;
}
.step-type-llm{background:var(--primary-g);border-color:var(--primary);color:var(--primary)}
.step-type-tool{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.3);color:var(--yellow)}

.step-name{font-weight:600;font-size:13px;flex:1}
.step-meta{display:flex;gap:12px;align-items:center;flex-shrink:0;flex-wrap:wrap}
.step-dur{font-family:var(--font-mono);font-size:12px;color:var(--cyan)}
.step-tokens{font-family:var(--font-mono);font-size:11px;color:var(--text-muted)}
.step-expand{color:var(--text-muted);font-size:14px;transition:transform .2s;flex-shrink:0}

/* Latency bar */
.latency-bar{height:3px;background:var(--bg-card2)}
.latency-fill{height:100%;transition:width .6s cubic-bezier(.4,0,.2,1)}
.fill-llm {background:linear-gradient(90deg,var(--primary),var(--cyan))}
.fill-tool{background:linear-gradient(90deg,var(--yellow),var(--green))}

/* Expandable payload body */
.step-body{display:none;border-top:1px solid var(--border)}
.step-body.open{display:block}
.payload-tabs{display:flex;border-bottom:1px solid var(--border)}
.tab-btn{
  padding:8px 16px;font-size:12px;font-weight:500;border:none;background:transparent;
  color:var(--text-muted);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;
  transition:all .15s;
}
.tab-btn.active{color:var(--primary);border-bottom-color:var(--primary)}
.tab-content{display:none;padding:14px 18px}
.tab-content.active{display:block}
pre.json-view{
  background:var(--bg-input);border:1px solid var(--border);border-radius:8px;
  padding:14px;font-family:var(--font-mono);font-size:11.5px;line-height:1.65;
  overflow-x:auto;white-space:pre-wrap;word-break:break-all;
  max-height:320px;overflow-y:auto;color:var(--text-dim);
}

/* ── Not-found ───────────────────────────────────────────────────────────── */
.not-found{text-align:center;padding:80px 24px}
.not-found-icon{font-size:48px;margin-bottom:16px}
</style>
</head>
<body>
<header>
  <div class="header-inner">
    <a href="/" class="logo">
      <span class="logo-icon">🛡</span>
      <span class="logo-name">phpkaiharness Monitor</span>
    </a>
    <a href="/" class="btn btn-ghost">← Back to Dashboard</a>
  </div>
</header>

<div class="main">

<?php if (! $session) { ?>
<div class="not-found">
  <div class="not-found-icon">🔍</div>
  <h2 style="margin-bottom:8px">Session Not Found</h2>
  <p style="color:var(--text-dim)"><?= $errorMsg ?? '' ?></p>
  <br>
  <a href="/" class="btn btn-ghost">← Dashboard</a>
</div>

<?php } else {
    $totalTokensPr = 0;
    $totalTokensCt = 0;
    $llmCalls = 0;
    $toolCalls = 0;
    $maxDur = 1;
    foreach ($session['details'] as $d) {
        $totalTokensPr += (int) $d['tokens_prompt'];
        $totalTokensCt += (int) $d['tokens_completion'];
        if ($d['type'] === 'llm_call') {
            $llmCalls++;
        } else {
            $toolCalls++;
        }
        $maxDur = max($maxDur, (int) $d['duration_ms']);
    }
    $badgeClass = methodBadgeClass($session['method']);
    ?>

<!-- Session header -->
<div class="session-header">
  <div class="sh-top">
    <div>
      <div class="sh-id">SESSION · <?= htmlspecialchars($session['id']) ?></div>
      <div style="margin-top:4px;color:var(--text-muted);font-size:12px"><?= htmlspecialchars($session['created_at']) ?></div>
    </div>
    <div class="sh-meta">
      <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($session['method']) ?></span>
    </div>
  </div>

  <div class="chips-row">
    <div class="stat-chip">
      <span class="chip-value" style="color:var(--cyan)"><?= fmtMs((int) $session['total_duration_ms']) ?></span>
      <span class="chip-label">Duration</span>
    </div>
    <div class="stat-chip">
      <span class="chip-value" style="color:var(--primary)"><?= number_format($totalTokensPr + $totalTokensCt) ?></span>
      <span class="chip-label">Total Tokens</span>
    </div>
    <div class="stat-chip">
      <span class="chip-value" style="color:var(--text)"><?= $session['iterations'] ?></span>
      <span class="chip-label">Iterations</span>
    </div>
    <div class="stat-chip">
      <span class="chip-value" style="color:var(--yellow)"><?= $toolCalls ?></span>
      <span class="chip-label">Tool Calls</span>
    </div>
    <div class="stat-chip">
      <span class="chip-value" style="color:var(--text-dim)"><?= $llmCalls ?></span>
      <span class="chip-label">LLM Calls</span>
    </div>
  </div>

  <div style="margin-bottom:8px;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.06em">Prompt</div>
  <div class="sh-prompt"><?= htmlspecialchars($session['prompt']) ?></div>

  <div style="margin-bottom:8px;font-size:12px;font-weight:600;color:var(--text-dim);text-transform:uppercase;letter-spacing:.06em">Response</div>
  <div class="sh-response"><?= htmlspecialchars($session['response'] ?? '') ?></div>
</div>

<!-- Timeline -->
<?php if (! empty($session['details'])) { ?>
<div class="timeline-header">
  <span>⏱</span> Execution Timeline
  <span style="font-size:12px;color:var(--text-muted);font-weight:400"><?= count($session['details']) ?> steps</span>
</div>
<div class="timeline">
  <?php foreach ($session['details'] as $i => $d) {
      $isLlm = $d['type'] === 'llm_call';
      $dur = (int) $d['duration_ms'];
      $barPct = $maxDur > 0 ? round($dur / $maxDur * 100) : 0;
      $stepNum = $i + 1;
      $stepId = "step-$i";
      $typeLabel = $isLlm ? 'LLM' : 'TOOL';
      ?>
  <div class="step-card">
    <!-- Latency indicator bar -->
    <div class="latency-bar">
      <div class="latency-fill <?= $isLlm ? 'fill-llm' : 'fill-tool' ?>" style="width:<?= $barPct ?>%"></div>
    </div>

    <!-- Clickable header -->
    <div class="step-header" onclick="toggleStep('<?= $stepId ?>', this)">
      <div class="step-num <?= $isLlm ? 'step-type-llm' : 'step-type-tool' ?>"><?= $typeLabel[0] ?></div>
      <div class="step-name">
        <?= htmlspecialchars($d['name']) ?>
        <span style="font-size:11px;font-weight:400;color:var(--text-muted);margin-left:6px"><?= $typeLabel ?></span>
      </div>
      <div class="step-meta">
        <span class="step-dur"><?= fmtMs($dur) ?></span>
        <?php if ($isLlm) { ?>
          <span class="step-tokens">↑<?= number_format((int) $d['tokens_prompt']) ?> ↓<?= number_format((int) $d['tokens_completion']) ?> tokens</span>
        <?php } ?>
      </div>
      <span class="step-expand">▼</span>
    </div>

    <!-- Expandable body with tabs -->
    <div class="step-body" id="<?= $stepId ?>">
      <div class="payload-tabs" id="<?= $stepId ?>-tabs">
        <button class="tab-btn active" onclick="switchTab('<?= $stepId ?>','payload',this)">📤 Request</button>
        <button class="tab-btn" onclick="switchTab('<?= $stepId ?>','response',this)">📥 Response</button>
      </div>
      <div class="tab-content active" id="<?= $stepId ?>-payload">
        <pre class="json-view"><?= jsonPretty($d['payload'] ?? '{}') ?></pre>
      </div>
      <div class="tab-content" id="<?= $stepId ?>-response">
        <pre class="json-view"><?php
              $resp = $d['response'] ?? '';
      $decoded = json_decode($resp, true);
      echo $decoded !== null
          ? htmlspecialchars(json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
          : htmlspecialchars($resp);
      ?></pre>
      </div>
    </div>
  </div>
  <?php } ?>
</div>
<?php } else { ?>
  <div style="text-align:center;padding:40px;color:var(--text-muted)">No step details recorded for this session.</div>
<?php } ?>

<?php } ?>

</div><!-- /main -->

<script>
function toggleStep(id, header) {
  const body = document.getElementById(id);
  const icon = header.querySelector('.step-expand');
  const open = body.classList.toggle('open');
  icon.style.transform = open ? 'rotate(180deg)' : '';
}

function switchTab(stepId, tab, btn) {
  const container = document.getElementById(stepId);
  // Deactivate all tab buttons and panels
  container.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  container.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));
  // Activate selected
  btn.classList.add('active');
  document.getElementById(stepId + '-' + tab).classList.add('active');
}
</script>
</body>
</html>
