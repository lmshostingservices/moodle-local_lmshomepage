<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Attendance notification log — admin report (v2.2 — world-class redesign).
 * Site Administration → Reports → Attendance Notification Log.
 * @package    local_lmshomepage
 * @copyright  2024 LMS Labs <support@lmslabs.com.au>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');

// require_login() is called implicitly by admin_externalpage_setup below.
admin_externalpage_setup('local_lmshomepage_report');

global $DB, $OUTPUT, $PAGE;

// ── Guard ─────────────────────────────────────────────────────────────────────
if (!$DB->get_manager()->table_exists('local_lmshomepage_log')) {
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('report_table_missing', 'local_lmshomepage'), \core\output\notification::NOTIFY_WARNING);
    echo $OUTPUT->footer();
    exit;
}

// ── Filter params ─────────────────────────────────────────────────────────────
$today         = date('Y-m-d');
$f_date_from   = optional_param('date_from',      date('Y-m-d', strtotime('-30 days')), PARAM_TEXT);
$f_date_to     = optional_param('date_to',        $today,  PARAM_TEXT);
$f_risk_level  = optional_param('risk_level',     'all',   PARAM_ALPHAEXT);
$f_courseid    = optional_param('courseid',       0,       PARAM_INT);
$f_recip_type  = optional_param('recipient_type', 'all',   PARAM_ALPHAEXT);
$f_student     = optional_param('student_name',   '',      PARAM_TEXT);
$f_sort        = optional_param('sort',           'timesent', PARAM_ALPHA);
$f_dir         = optional_param('dir',            'DESC',  PARAM_ALPHA);
$f_page        = optional_param('page',           0,       PARAM_INT);
$export        = optional_param('export',         '',      PARAM_ALPHA);

$sort_cols = ['timesent', 'student_name', 'course_name', 'percentage', 'risk_level', 'recipient_type'];
if (!in_array($f_sort, $sort_cols, true)) { $f_sort = 'timesent'; }
$f_dir    = strtoupper($f_dir) === 'ASC' ? 'ASC' : 'DESC';
$per_page = 50;

$ts_from = (@strtotime($f_date_from . ' 00:00:00')) ?: strtotime('-30 days midnight');
$ts_to   = (@strtotime($f_date_to   . ' 23:59:59')) ?: time();

// ── Date preset detection ─────────────────────────────────────────────────────
$week_from  = date('Y-m-d', strtotime('monday this week'));
$month_from = date('Y-m-01');
$year_from  = date('Y-01-01');

$active_preset = 'custom';
if ($f_date_from === $today      && $f_date_to === $today) $active_preset = 'today';
elseif ($f_date_from === $week_from  && $f_date_to === $today) $active_preset = 'week';
elseif ($f_date_from === $month_from && $f_date_to === $today) $active_preset = 'month';
elseif ($f_date_from === $year_from  && $f_date_to === $today) $active_preset = 'year';

// ── Build base WHERE (no risk filter — used for per-risk counts) ──────────────
$base_where  = ['timesent >= ?', 'timesent <= ?'];
$base_params = [$ts_from, $ts_to];

if ($f_courseid > 0) {
    $base_where[]  = 'courseid = ?';
    $base_params[] = $f_courseid;
}
if ($f_recip_type !== 'all' && in_array($f_recip_type, ['student','teacher','admin'], true)) {
    $base_where[]  = 'recipient_type = ?';
    $base_params[] = $f_recip_type;
}
if ($f_student !== '') {
    $base_where[]  = $DB->sql_like('student_name', '?', false, false);
    $base_params[] = '%' . $DB->sql_like_escape($f_student) . '%';
}
$base_sql = 'WHERE ' . implode(' AND ', $base_where);

// ── Per-risk counts (for the filter buttons) ──────────────────────────────────
$risk_cnt_rows = $DB->get_records_sql(
    "SELECT risk_level, COUNT(*) AS cnt FROM {local_lmshomepage_log} {$base_sql} GROUP BY risk_level",
    $base_params
);
$rc = ['all' => 0, 'low' => 0, 'medium' => 0, 'high' => 0];
foreach ($risk_cnt_rows as $row) {
    $rc[$row->risk_level] = (int)$row->cnt;
    $rc['all'] += (int)$row->cnt;
}

// ── Full WHERE (with risk filter) ─────────────────────────────────────────────
$where  = $base_where;
$params = $base_params;
if ($f_risk_level !== 'all' && in_array($f_risk_level, ['low','medium','high'], true)) {
    $where[]  = 'risk_level = ?';
    $params[] = $f_risk_level;
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── CSV export ────────────────────────────────────────────────────────────────
if ($export === 'csv') {
    $rows  = $DB->get_records_sql("SELECT * FROM {local_lmshomepage_log} {$where_sql} ORDER BY {$f_sort} {$f_dir}", $params);
    $rl    = ['low' => 'Low Risk', 'medium' => 'Medium Risk', 'high' => 'High Risk'];
    $fname = 'attendance_notifications_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache'); header('Pragma: no-cache'); header('Expires: 0');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['Date','Time','Student Name','Student Email','Course','Activity','Attendance %','KPI Threshold %','Risk Level','Recipient Type','Recipient Name','Email Subject']);
    foreach ($rows as $r) {
        fputcsv($fh, [
            date('d/m/Y',$r->timesent), date('H:i:s',$r->timesent),
            $r->student_name, $r->student_email, $r->course_name, $r->activity_name,
            $r->percentage.'%', $r->kpi_threshold.'%',
            $rl[$r->risk_level] ?? ucfirst($r->risk_level),
            ucfirst($r->recipient_type), $r->recipient_name, $r->subject,
        ]);
    }
    fclose($fh); exit;
}

// ── Fetch display data ────────────────────────────────────────────────────────
$total_count = $DB->count_records_sql("SELECT COUNT(*) FROM {local_lmshomepage_log} {$where_sql}", $params);
$records     = $DB->get_records_sql(
    "SELECT * FROM {local_lmshomepage_log} {$where_sql} ORDER BY {$f_sort} {$f_dir} LIMIT {$per_page} OFFSET " . ($f_page * $per_page),
    $params
);

// Stats aggregation (from base — not filtered by risk level for consistency)
$all_stats      = $DB->get_records_sql("SELECT * FROM {local_lmshomepage_log} {$base_sql}", $base_params);
$today_start    = mktime(0,0,0);
$today_count    = count(array_filter($all_stats, static fn($r) => $r->timesent >= $today_start));
$unique_students = count(array_unique(array_column(array_values((array)$all_stats),'userid')));

// Courses for advanced filter
$all_courses = $DB->get_records_sql("SELECT DISTINCT courseid, course_name FROM {local_lmshomepage_log} ORDER BY course_name", []);

// Last notification time
$latest = $DB->get_record_sql("SELECT MAX(timesent) AS ts FROM {local_lmshomepage_log}", []);
$latest_ts = $latest ? (int)$latest->ts : 0;

// ── URL builder helpers ───────────────────────────────────────────────────────
$report_url   = new moodle_url('/local/lmshomepage/report.php');
$filter_base  = ['sort' => $f_sort, 'dir' => $f_dir, 'date_from' => $f_date_from, 'date_to' => $f_date_to,
                  'courseid' => $f_courseid, 'recipient_type' => $f_recip_type, 'student_name' => $f_student];

$risk_url  = fn(string $r) => (new moodle_url($report_url, $filter_base + ['risk_level' => $r, 'page' => 0]))->out(false);
$date_url  = fn(string $from, string $to) => (new moodle_url($report_url, $filter_base + ['date_from' => $from, 'date_to' => $to, 'risk_level' => $f_risk_level, 'page' => 0]))->out(false);
$sort_url  = fn(string $col) => (new moodle_url($report_url, $filter_base + ['risk_level' => $f_risk_level, 'sort' => $col, 'dir' => ($f_sort === $col && $f_dir === 'DESC') ? 'ASC' : 'DESC']))->out(false);
$csv_url   = (new moodle_url($report_url, $filter_base + ['risk_level' => $f_risk_level, 'export' => 'csv']))->out(false);

// ── Page output ───────────────────────────────────────────────────────────────
$PAGE->set_title('Attendance Notification Log');
$PAGE->set_heading('Attendance Notification Log');
echo $OUTPUT->header();

// ── Inline helpers ────────────────────────────────────────────────────────────
$risk_badge = function (string $level): string {
    $m = ['low'=>['🟡 Low Risk','#854d0e','#fefce8','#fde047'],'medium'=>['🟠 Medium Risk','#9a3412','#fff7ed','#fb923c'],'high'=>['🔴 High Risk','#7f1d1d','#fef2f2','#f87171']];
    [$l,$c,$bg,$bd] = $m[$level] ?? ['Unknown','#374151','#f3f4f6','#d1d5db'];
    return "<span style='display:inline-flex;align-items:center;gap:4px;padding:3px 11px;border-radius:999px;background:{$bg};color:{$c};border:1px solid {$bd};font-size:.75rem;font-weight:700;white-space:nowrap;'>{$l}</span>";
};
$recip_badge = function (string $type): string {
    $m = ['student'=>['Student','#1e3a8a','#dbeafe','#93c5fd'],'teacher'=>['Trainer','#064e3b','#d1fae5','#6ee7b7'],'admin'=>['Admin','#4c1d95','#ede9fe','#a78bfa']];
    [$l,$c,$bg,$bd] = $m[$type] ?? [ucfirst($type),'#374151','#f3f4f6','#d1d5db'];
    return "<span style='display:inline-flex;align-items:center;padding:3px 10px;border-radius:999px;background:{$bg};color:{$c};border:1px solid {$bd};font-size:.73rem;font-weight:700;white-space:nowrap;'>{$l}</span>";
};
$pct_bar = function (int $pct): string {
    $w = min(100, max(0, $pct));
    $c = $pct < 60 ? '#ef4444' : ($pct < 70 ? '#f97316' : '#f59e0b');
    return "<div style='display:flex;align-items:center;gap:7px;min-width:100px;'>
      <div style='flex:1;background:#e2e8f0;border-radius:999px;height:7px;overflow:hidden;'>
        <div style='width:{$w}%;height:7px;background:{$c};border-radius:999px;'></div>
      </div>
      <span style='font-size:.8rem;font-weight:800;color:{$c};min-width:34px;text-align:right;'>{$pct}%</span>
    </div>";
};
$sort_th = function (string $col, string $label) use ($f_sort, $f_dir, $sort_url): string {
    $arrow = $f_sort === $col ? ($f_dir === 'DESC' ? ' ↓' : ' ↑') : '';
    return '<a href="'.$sort_url($col).'" style="color:inherit;text-decoration:none;display:flex;align-items:center;gap:4px;white-space:nowrap;">'
         .htmlspecialchars($label,ENT_QUOTES).'<span style="opacity:.5;font-size:.7em;">'.$arrow.'</span></a>';
};
?>

<!----------------------------------------------------------------------
  STYLES
----------------------------------------------------------------------->
<style>
:root {
  --navy:    #1e3a5f;
  --gold:    #f59e0b;
  --low-c:   #92400e; --low-bg:  #fffbeb; --low-bd: #fcd34d;
  --med-c:   #9a3412; --med-bg:  #fff7ed; --med-bd: #fb923c;
  --hi-c:    #7f1d1d; --hi-bg:   #fef2f2; --hi-bd:  #fca5a5;
}
.lrw { font-family: -apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif; color: #1e293b; }

/* ── Hero banner ────────────────────────────────────────────────────── */
.lr-hero {
  position: relative; overflow: hidden; border-radius: 18px; margin-bottom: 22px;
  background: linear-gradient(135deg, #0c1a3d 0%, #1a2f6b 45%, #0d1f4a 100%);
  padding: 34px 36px 28px; box-shadow: 0 8px 40px rgba(12,26,61,.35);
}
.lr-hero::before {
  content:''; position:absolute; inset:0;
  background: radial-gradient(ellipse 60% 80% at 80% 50%, rgba(99,102,241,.15) 0%, transparent 70%),
              radial-gradient(ellipse 40% 60% at 20% 80%, rgba(245,158,11,.10) 0%, transparent 60%);
}
.lr-hero-inner { position:relative; z-index:1; display:flex; align-items:center; gap:20px; flex-wrap:wrap; }
.lr-hero h1 { color:#fff; margin:0 0 5px; font-size:1.65rem; font-weight:800; letter-spacing:-.02em; }
.lr-hero-sub { color:rgba(255,255,255,.6); font-size:.88rem; margin:0; }
.lr-hero-sub strong { color:rgba(255,255,255,.9); }
.lr-export-btn {
  margin-left:auto; background:#f59e0b; color:#1a1a1a; border:none; border-radius:10px;
  padding:11px 22px; font-weight:800; font-size:.88rem; text-decoration:none;
  display:inline-flex; align-items:center; gap:8px; white-space:nowrap; cursor:pointer;
  box-shadow:0 2px 12px rgba(245,158,11,.4); transition:all .15s; flex-shrink:0;
}
.lr-export-btn:hover { background:#d97706; color:#1a1a1a; transform:translateY(-1px); box-shadow:0 4px 18px rgba(245,158,11,.5); }

/* ── Quick filter bar ────────────────────────────────────────────────── */
.lr-qf-bar {
  background:#fff; border-radius:14px; margin-bottom:20px;
  border:1px solid #e2e8f0; box-shadow:0 2px 12px rgba(0,0,0,.06);
  padding:18px 24px; display:flex; flex-wrap:wrap; gap:20px; align-items:center;
}
.lr-qf-section { display:flex; flex-direction:column; gap:7px; }
.lr-qf-label {
  font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em;
  color:#94a3b8; display:flex; align-items:center; gap:5px;
}
.lr-pill-row { display:flex; flex-wrap:wrap; gap:6px; }

/* Risk pills */
.lr-risk-pill {
  display:inline-flex; align-items:center; gap:6px; padding:8px 16px; border-radius:999px;
  font-size:.82rem; font-weight:700; cursor:pointer; text-decoration:none;
  border:2px solid transparent; transition:all .15s; white-space:nowrap;
}
.lr-risk-pill.all      { background:#f1f5f9; color:#475569; border-color:#e2e8f0; }
.lr-risk-pill.all.active,.lr-risk-pill.all:hover { background:#1e3a5f; color:#fff; border-color:#1e3a5f; }
.lr-risk-pill.low      { background:#fffbeb; color:#92400e; border-color:#fcd34d; }
.lr-risk-pill.low.active,.lr-risk-pill.low:hover { background:#f59e0b; color:#1a1a1a; border-color:#d97706; box-shadow:0 2px 8px rgba(245,158,11,.3); }
.lr-risk-pill.medium   { background:#fff7ed; color:#9a3412; border-color:#fb923c; }
.lr-risk-pill.medium.active,.lr-risk-pill.medium:hover { background:#ea580c; color:#fff; border-color:#c2410c; box-shadow:0 2px 8px rgba(234,88,12,.3); }
.lr-risk-pill.high     { background:#fef2f2; color:#7f1d1d; border-color:#fca5a5; }
.lr-risk-pill.high.active,.lr-risk-pill.high:hover { background:#dc2626; color:#fff; border-color:#b91c1c; box-shadow:0 2px 8px rgba(220,38,38,.3); }
.lr-pill-count {
  display:inline-flex; align-items:center; justify-content:center;
  min-width:20px; height:20px; padding:0 5px;
  border-radius:999px; font-size:.7rem; font-weight:800;
  background:rgba(0,0,0,.1); color:inherit;
}
.lr-risk-pill.active .lr-pill-count { background:rgba(255,255,255,.25); }

/* Date pills */
.lr-date-pill {
  display:inline-flex; align-items:center; padding:8px 16px; border-radius:999px;
  font-size:.82rem; font-weight:600; cursor:pointer; text-decoration:none;
  background:#f8fafc; color:#475569; border:2px solid #e2e8f0; white-space:nowrap;
  transition:all .15s;
}
.lr-date-pill:hover  { background:#e0e7ff; color:#3730a3; border-color:#a5b4fc; }
.lr-date-pill.active { background:#1e3a5f; color:#fff; border-color:#1e3a5f; }

/* ── Stat cards ──────────────────────────────────────────────────────── */
.lr-stats { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:14px; margin-bottom:20px; }
.lr-stat {
  border-radius:14px; padding:20px 18px 16px; border:1px solid transparent;
  display:flex; flex-direction:column; gap:5px; position:relative; overflow:hidden;
  box-shadow:0 2px 10px rgba(0,0,0,.06); transition:transform .15s;
}
.lr-stat:hover { transform:translateY(-2px); }
.lr-stat::after { content:''; position:absolute; right:-20px; bottom:-20px; width:80px; height:80px; border-radius:50%; opacity:.08; background:currentColor; }
.lr-stat .sl  { font-size:.68rem; font-weight:800; text-transform:uppercase; letter-spacing:.07em; opacity:.7; }
.lr-stat .sv  { font-size:2.1rem; font-weight:900; line-height:1; letter-spacing:-.03em; }
.lr-stat .ss  { font-size:.72rem; opacity:.6; }
.s-total  { background:#eff6ff; color:#1d4ed8; border-color:#bfdbfe; }
.s-unique { background:#f5f3ff; color:#6d28d9; border-color:#ddd6fe; }
.s-today  { background:#ecfdf5; color:#059669; border-color:#a7f3d0; }
.s-high   { background:#fef2f2; color:#dc2626; border-color:#fecaca; }
.s-medium { background:#fff7ed; color:#ea580c; border-color:#fed7aa; }
.s-low    { background:#fffbeb; color:#d97706; border-color:#fde68a; }

/* ── Adv. filter card ────────────────────────────────────────────────── */
.lr-adv-wrap { margin-bottom:20px; }
.lr-adv-toggle {
  display:flex; align-items:center; gap:8px; cursor:pointer;
  font-size:.8rem; font-weight:700; color:#475569; background:none; border:none;
  padding:0; margin-bottom:10px; font-family:inherit;
}
.lr-adv-toggle:hover { color:#1e3a5f; }
.lr-adv-body {
  background:#fff; border:1px solid #e2e8f0; border-radius:12px; padding:20px 24px;
  display:grid; grid-template-columns:repeat(auto-fill,minmax(190px,1fr)); gap:14px; align-items:end;
}
.lr-adv-body.hidden { display:none; }
.lr-fi label { display:block; font-size:.75rem; font-weight:700; color:#374151; margin-bottom:4px; letter-spacing:.02em; }
.lr-fi input,.lr-fi select {
  width:100%; padding:9px 11px; border:1px solid #d1d5db; border-radius:8px;
  font-size:.85rem; color:#111827; background:#f9fafb; box-sizing:border-box;
  transition:border-color .15s,box-shadow .15s; font-family:inherit;
}
.lr-fi input:focus,.lr-fi select:focus { outline:none; border-color:#1e3a5f; box-shadow:0 0 0 3px rgba(30,58,95,.1); }
.lr-adv-actions { display:flex; gap:8px; align-items:center; grid-column:1/-1; }
.lr-btn-p {
  background:#1e3a5f; color:#fff; border:none; border-radius:8px;
  padding:9px 20px; font-weight:700; font-size:.85rem; cursor:pointer;
  transition:background .15s; font-family:inherit;
}
.lr-btn-p:hover { background:#152d4e; }
.lr-btn-g {
  background:#fff; color:#6b7280; border:1px solid #d1d5db; border-radius:8px;
  padding:8px 16px; font-weight:600; font-size:.85rem; cursor:pointer;
  text-decoration:none; display:inline-block; transition:all .15s; font-family:inherit;
}
.lr-btn-g:hover { color:#374151; border-color:#9ca3af; }

/* ── Table ───────────────────────────────────────────────────────────── */
.lr-table-card {
  background:#fff; border-radius:16px; border:1px solid #e2e8f0;
  box-shadow:0 2px 12px rgba(0,0,0,.06); overflow:hidden;
}
.lr-table-hdr {
  display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap;
  gap:12px; padding:18px 24px 14px; border-bottom:1px solid #f1f5f9;
}
.lr-table-hdr .lr-count { font-size:.88rem; color:#64748b; }
.lr-table-hdr .lr-count strong { color:#1e293b; font-weight:800; }
.lr-active-chips { display:flex; flex-wrap:wrap; gap:5px; }
.lr-chip {
  display:inline-flex; align-items:center; gap:5px; padding:3px 10px; border-radius:999px;
  background:#f1f5f9; color:#475569; border:1px solid #e2e8f0; font-size:.72rem; font-weight:700;
}
.lr-tbl { width:100%; border-collapse:collapse; font-size:.84rem; }
.lr-tbl thead th {
  background:#f8fafc; padding:11px 16px; text-align:left; border-bottom:2px solid #e2e8f0;
  font-size:.7rem; font-weight:800; text-transform:uppercase; letter-spacing:.06em; color:#64748b;
  white-space:nowrap;
}
.lr-tbl tbody tr { border-bottom:1px solid #f1f5f9; transition:background .1s; }
.lr-tbl tbody tr:last-child { border-bottom:none; }
.lr-tbl tbody tr:hover { background:#f8faff; }
.lr-tbl td { padding:12px 16px; vertical-align:middle; }
/* Left-accent by risk */
.lr-tbl .row-high   { border-left:4px solid #ef4444; }
.lr-tbl .row-medium { border-left:4px solid #f97316; }
.lr-tbl .row-low    { border-left:4px solid #f59e0b; }
/* Cell types */
.td-dt    { white-space:nowrap; color:#64748b; font-size:.8rem; }
.td-name  { font-weight:700; color:#1e293b; }
.td-email { font-size:.75rem; color:#94a3b8; margin-top:2px; }
.td-course { color:#374151; }
.td-act   { font-size:.76rem; color:#94a3b8; margin-top:2px; }
.td-subj  { color:#374151; font-size:.81rem; max-width:260px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ── Empty state ─────────────────────────────────────────────────────── */
.lr-empty { text-align:center; padding:64px 24px; }
.lr-empty .lr-empty-icon { font-size:3.5rem; margin-bottom:16px; }
.lr-empty h3 { margin:0 0 8px; font-size:1.05rem; color:#374151; font-weight:800; }
.lr-empty p  { margin:0; font-size:.875rem; color:#94a3b8; }

/* ── Pagination ──────────────────────────────────────────────────────── */
.lr-pages { display:flex; justify-content:center; align-items:center; gap:6px; padding:18px; border-top:1px solid #f1f5f9; flex-wrap:wrap; }
.lr-pg {
  display:inline-flex; align-items:center; justify-content:center;
  min-width:36px; height:36px; padding:0 10px; border-radius:8px;
  border:1px solid #e2e8f0; background:#fff; color:#374151;
  font-size:.82rem; font-weight:600; text-decoration:none; transition:all .15s;
}
.lr-pg:hover { background:#1e3a5f; color:#fff; border-color:#1e3a5f; }
.lr-pg.cur   { background:#1e3a5f; color:#fff; border-color:#1e3a5f; font-weight:900; }
.lr-pg-info  { font-size:.8rem; color:#94a3b8; padding:0 4px; }
</style>

<div class="lrw">

<?php
$filter_base_no_risk = ['sort'=>$f_sort,'dir'=>$f_dir,'date_from'=>$f_date_from,'date_to'=>$f_date_to,
                         'courseid'=>$f_courseid,'recipient_type'=>$f_recip_type,'student_name'=>$f_student,'page'=>0];
?>

<!-- ── HERO BANNER ──────────────────────────────────────────────────────── -->
<div class="lr-hero">
  <div class="lr-hero-inner">
    <div style="flex:1;min-width:220px;">
      <h1>📬 Attendance Notification Log</h1>
      <p class="lr-hero-sub">
        <?php if ($latest_ts): ?>
          Last sent: <strong><?= date('j M Y \a\t g:ia', $latest_ts) ?></strong> &nbsp;·&nbsp;
        <?php endif; ?>
        <strong><?= number_format($rc['all']) ?></strong> records in period
        <?php if ($f_risk_level !== 'all'): ?>
          &nbsp;·&nbsp; filtered: <strong><?= htmlspecialchars(ucfirst($f_risk_level).' Risk', ENT_QUOTES) ?></strong>
        <?php endif; ?>
      </p>
    </div>
    <a href="<?= $csv_url ?>" class="lr-export-btn">⬇ Export CSV</a>
  </div>
</div>

<!-- ── QUICK FILTER BAR ──────────────────────────────────────────────────── -->
<div class="lr-qf-bar">

  <!-- Risk level pills -->
  <div class="lr-qf-section">
    <div class="lr-qf-label">🎯 Risk level</div>
    <div class="lr-pill-row">
      <?php
        $risk_defs = [
          'all'    => ['All notifications', ''],
          'low'    => ['🟡 Low Risk',        'low'],
          'medium' => ['🟠 Medium Risk',     'medium'],
          'high'   => ['🔴 High Risk',       'high'],
        ];
        foreach ($risk_defs as $key => [$label, $cls]):
          $is_active = $f_risk_level === $key;
          $count     = $rc[$key] ?? 0;
      ?>
        <a href="<?= $risk_url($key) ?>"
           class="lr-risk-pill <?= $cls ?: 'all' ?><?= $is_active ? ' active' : '' ?>">
          <?= $label ?>
          <span class="lr-pill-count"><?= number_format($count) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Divider -->
  <div style="width:1px;background:#e2e8f0;height:52px;flex-shrink:0;"></div>

  <!-- Date presets -->
  <div class="lr-qf-section">
    <div class="lr-qf-label">📅 Date range</div>
    <div class="lr-pill-row">
      <?php
        $date_presets = [
          ['Today',      $today,      $today,  'today'],
          ['This Week',  $week_from,  $today,  'week'],
          ['This Month', $month_from, $today,  'month'],
          ['This Year',  $year_from,  $today,  'year'],
        ];
        foreach ($date_presets as [$plabel, $pfrom, $pto, $pkey]):
      ?>
        <a href="<?= $date_url($pfrom, $pto) ?>"
           class="lr-date-pill<?= $active_preset === $pkey ? ' active' : '' ?>">
          <?= $plabel ?>
        </a>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Showing indicator -->
  <div style="margin-left:auto;text-align:right;flex-shrink:0;">
    <div style="font-size:.7rem;font-weight:800;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;margin-bottom:3px;">Showing</div>
    <div style="font-size:1.4rem;font-weight:900;color:#1e293b;line-height:1;"><?= number_format($total_count) ?></div>
    <div style="font-size:.72rem;color:#94a3b8;">of <?= number_format($rc['all']) ?> total</div>
  </div>
</div>

<!-- ── STAT CARDS ────────────────────────────────────────────────────────── -->
<div class="lr-stats">
  <div class="lr-stat s-total">
    <div class="sl">Total sent</div>
    <div class="sv"><?= number_format($rc['all']) ?></div>
    <div class="ss">in period</div>
  </div>
  <div class="lr-stat s-unique">
    <div class="sl">Students affected</div>
    <div class="sv"><?= number_format($unique_students) ?></div>
    <div class="ss">unique learners</div>
  </div>
  <div class="lr-stat s-today">
    <div class="sl">Sent today</div>
    <div class="sv"><?= number_format($today_count) ?></div>
    <div class="ss"><?= date('j M Y') ?></div>
  </div>
  <div class="lr-stat s-high" style="cursor:pointer;" onclick="location.href='<?= $risk_url('high') ?>'">
    <div class="sl">🔴 High risk</div>
    <div class="sv"><?= number_format($rc['high']) ?></div>
    <div class="ss">click to filter</div>
  </div>
  <div class="lr-stat s-medium" style="cursor:pointer;" onclick="location.href='<?= $risk_url('medium') ?>'">
    <div class="sl">🟠 Medium risk</div>
    <div class="sv"><?= number_format($rc['medium']) ?></div>
    <div class="ss">click to filter</div>
  </div>
  <div class="lr-stat s-low" style="cursor:pointer;" onclick="location.href='<?= $risk_url('low') ?>'">
    <div class="sl">🟡 Low risk</div>
    <div class="sv"><?= number_format($rc['low']) ?></div>
    <div class="ss">click to filter</div>
  </div>
</div>

<!-- ── ADVANCED FILTERS (collapsible) ───────────────────────────────────── -->
<div class="lr-adv-wrap">
  <button class="lr-adv-toggle" onclick="toggleAdv(this)" aria-expanded="false">
    <span id="advIcon">▶</span>
    Advanced filters
    <?php if ($f_courseid > 0 || $f_recip_type !== 'all' || $f_student !== '' || $active_preset === 'custom'): ?>
      <span style="background:#1e3a5f;color:#fff;border-radius:999px;padding:1px 8px;font-size:.68rem;">active</span>
    <?php endif; ?>
  </button>
  <div class="lr-adv-body hidden" id="advBody">
    <form method="GET" action="<?= $report_url->out(false) ?>">
      <input type="hidden" name="sort"       value="<?= s($f_sort) ?>">
      <input type="hidden" name="dir"        value="<?= s($f_dir) ?>">
      <input type="hidden" name="risk_level" value="<?= s($f_risk_level) ?>">

      <div class="lr-fi">
        <label>Date from</label>
        <input type="date" name="date_from" value="<?= s($f_date_from) ?>">
      </div>
      <div class="lr-fi">
        <label>Date to</label>
        <input type="date" name="date_to" value="<?= s($f_date_to) ?>">
      </div>
      <div class="lr-fi">
        <label>Course</label>
        <select name="courseid">
          <option value="0">All courses</option>
          <?php foreach ($all_courses as $c): ?>
            <option value="<?= (int)$c->courseid ?>" <?= $f_courseid === (int)$c->courseid ? 'selected' : '' ?>>
              <?= htmlspecialchars($c->course_name, ENT_QUOTES) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="lr-fi">
        <label>Recipient type</label>
        <select name="recipient_type">
          <option value="all"     <?= $f_recip_type === 'all'     ? 'selected':'' ?>>All recipients</option>
          <option value="student" <?= $f_recip_type === 'student' ? 'selected':'' ?>>Student only</option>
          <option value="teacher" <?= $f_recip_type === 'teacher' ? 'selected':'' ?>>Trainer only</option>
          <option value="admin"   <?= $f_recip_type === 'admin'   ? 'selected':'' ?>>Admin only</option>
        </select>
      </div>
      <div class="lr-fi">
        <label>Student name</label>
        <input type="text" name="student_name" value="<?= htmlspecialchars($f_student, ENT_QUOTES) ?>" placeholder="Search…">
      </div>
      <div class="lr-adv-actions">
        <button type="submit" class="lr-btn-p">Apply</button>
        <a href="<?= $report_url->out(false) ?>" class="lr-btn-g">Reset all</a>
      </div>
    </form>
  </div>
</div>

<!-- ── DATA TABLE ────────────────────────────────────────────────────────── -->
<div class="lr-table-card">
  <div class="lr-table-hdr">
    <div>
      <div class="lr-count">
        Showing <strong><?= number_format(min($total_count, count($records))) ?></strong>
        of <strong><?= number_format($total_count) ?></strong> record<?= $total_count !== 1 ? 's' : '' ?>
      </div>
      <div class="lr-active-chips" style="margin-top:6px;">
        <?php if ($f_risk_level !== 'all'): ?>
          <span class="lr-chip">Risk: <?= htmlspecialchars(ucfirst($f_risk_level), ENT_QUOTES) ?></span>
        <?php endif; ?>
        <?php if ($active_preset !== 'custom'): ?>
          <span class="lr-chip">📅 <?= htmlspecialchars(ucfirst($active_preset), ENT_QUOTES) ?></span>
        <?php else: ?>
          <span class="lr-chip">📅 <?= htmlspecialchars($f_date_from.' → '.$f_date_to, ENT_QUOTES) ?></span>
        <?php endif; ?>
        <?php if ($f_courseid > 0): ?>
          <span class="lr-chip">Course filter active</span>
        <?php endif; ?>
        <?php if ($f_student !== ''): ?>
          <span class="lr-chip">Student: "<?= htmlspecialchars($f_student, ENT_QUOTES) ?>"</span>
        <?php endif; ?>
      </div>
    </div>
    <a href="<?= $csv_url ?>" class="lr-btn-g" style="font-size:.8rem;">⬇ Export CSV</a>
  </div>

  <?php if (empty($records)): ?>
    <div class="lr-empty">
      <div class="lr-empty-icon">📭</div>
      <h3>No notifications found</h3>
      <p>No attendance notifications match the current filters. Try a different date range or risk level.</p>
      <a href="<?= $report_url->out(false) ?>" class="lr-btn-p" style="display:inline-block;margin-top:16px;">Clear all filters</a>
    </div>
  <?php else: ?>
    <div style="overflow-x:auto;">
      <table class="lr-tbl">
        <thead>
          <tr>
            <th><?= $sort_th('timesent',       'Date / Time') ?></th>
            <th><?= $sort_th('student_name',   'Student') ?></th>
            <th><?= $sort_th('course_name',    'Course') ?></th>
            <th><?= $sort_th('percentage',     'Attendance') ?></th>
            <th><?= $sort_th('risk_level',     'Risk Level') ?></th>
            <th><?= $sort_th('recipient_type', 'Recipient') ?></th>
            <th>Recipient name</th>
            <th>Subject sent</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($records as $r): ?>
            <tr class="row-<?= htmlspecialchars($r->risk_level, ENT_QUOTES) ?>">
              <td class="td-dt">
                <div style="font-weight:600;color:#374151;"><?= date('j M Y', $r->timesent) ?></div>
                <div style="color:#94a3b8;font-size:.76rem;"><?= date('H:i', $r->timesent) ?></div>
              </td>
              <td>
                <div class="td-name"><?= htmlspecialchars($r->student_name,  ENT_QUOTES) ?></div>
                <div class="td-email"><?= htmlspecialchars($r->student_email, ENT_QUOTES) ?></div>
              </td>
              <td>
                <div class="td-course"><?= htmlspecialchars($r->course_name,   ENT_QUOTES) ?></div>
                <div class="td-act"><?= htmlspecialchars($r->activity_name, ENT_QUOTES) ?></div>
              </td>
              <td><?= $pct_bar((int)$r->percentage) ?></td>
              <td><?= $risk_badge($r->risk_level) ?></td>
              <td><?= $recip_badge($r->recipient_type) ?></td>
              <td style="color:#374151;font-size:.82rem;"><?= htmlspecialchars($r->recipient_name, ENT_QUOTES) ?></td>
              <td class="td-subj" title="<?= htmlspecialchars($r->subject, ENT_QUOTES) ?>">
                <?= htmlspecialchars($r->subject, ENT_QUOTES) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_count > $per_page):
      $total_pages = (int)ceil($total_count / $per_page);
      $pg_url = fn(int $p) => (new moodle_url($report_url, $filter_base_no_risk + ['risk_level' => $f_risk_level, 'page' => $p]))->out(false);
    ?>
      <div class="lr-pages">
        <?php if ($f_page > 0): ?>
          <a href="<?= $pg_url($f_page - 1) ?>" class="lr-pg">← Prev</a>
        <?php endif; ?>

        <?php
          $start = max(0, $f_page - 3); $end = min($total_pages - 1, $f_page + 3);
          if ($start > 0): ?><a href="<?= $pg_url(0) ?>" class="lr-pg">1</a><?php if ($start > 1): ?><span class="lr-pg-info">…</span><?php endif; endif;
          for ($i = $start; $i <= $end; $i++): ?>
            <a href="<?= $pg_url($i) ?>" class="lr-pg<?= $i === $f_page ? ' cur' : '' ?>"><?= $i + 1 ?></a>
          <?php endfor;
          if ($end < $total_pages - 1): ?><?php if ($end < $total_pages - 2): ?><span class="lr-pg-info">…</span><?php endif; ?><a href="<?= $pg_url($total_pages - 1) ?>" class="lr-pg"><?= $total_pages ?></a><?php endif; ?>

        <?php if ($f_page < $total_pages - 1): ?>
          <a href="<?= $pg_url($f_page + 1) ?>" class="lr-pg">Next →</a>
        <?php endif; ?>
        <span class="lr-pg-info">Page <?= $f_page + 1 ?> of <?= $total_pages ?> &nbsp;(<?= number_format($total_count) ?> records)</span>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

</div><!-- .lrw -->

<script>
function toggleAdv (btn) {
  var body    = document.getElementById('advBody');
  var icon    = document.getElementById('advIcon');
  var open    = body.classList.toggle('hidden');
  icon.textContent = open ? '▶' : '▼';
  btn.setAttribute('aria-expanded', !open);
}
// Auto-open if advanced filters are active
<?php if ($f_courseid > 0 || $f_recip_type !== 'all' || $f_student !== '' || $active_preset === 'custom'): ?>
document.addEventListener('DOMContentLoaded', function () {
  var btn = document.querySelector('.lr-adv-toggle');
  if (btn) toggleAdv(btn);
});
<?php endif; ?>
</script>

<?php echo $OUTPUT->footer();
