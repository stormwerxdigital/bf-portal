<?php
/*
 * A new session screen, cut down to what the Calendar's mark fills: the
 * student, the date, What happened, and Cancelled by and Why. The real
 * admin script runs on it; the server is a stub that has a Missed mark for
 * student 501 on 2026-11-18 and nothing else. Made-up names only.
 */
$root = dirname(dirname(__DIR__));
$html = '<!doctype html><html><head><meta charset="utf-8"><title>New session</title>'
  . '<script>' . file_get_contents(__DIR__ . '/jquery.js') . '</script>'
  . '<script>window.BFTD = { ajax_url: "/ajax", nonce: "n" }; window.__asked = []; jQuery.fn.sortable = function () { return this; };'
  . 'jQuery.post = function (u, d) { window.__asked.push(d); var m = (d.action === "bftd_attendance_mark" && d.student_id === "501" && d.date === "2026-11-18")'
  . ' ? { status: "missed", missed_by: "31", missed_why: "Family away", label: "Missed" } : null;'
  . ' return jQuery.Deferred().resolve({ success: true, data: { mark: m } }).promise(); };</script>'
  . '</head><body class="wp-admin post-new-php">'
  . '<select id="bftd_student"><option value="0">Choose a student</option><option value="501">Pat Example</option><option value="502">Sam Other</option></select>'
  . '<input type="date" id="bftd_session_session_date">'
  . '<select id="bftd_session_status"><option value="held">Attended</option><option value="late">Late start</option><option value="rescheduled">Rescheduled</option><option value="missed">Missed</option><option value="noshow">No show</option><option value="pending">Pending</option></select>'
  . '<select id="bftd_session_missed_by"><option value="">Choose someone</option><option value="31">Pat Parent (Client)</option></select>'
  . '<textarea id="bftd_session_missed_why"></textarea>'
  . '<script>' . file_get_contents($root . '/assets/js/bftd-admin.js') . '</script>'
  . '</body></html>';
file_put_contents(__DIR__ . '/session-mark.html', $html);
echo __DIR__ . '/session-mark.html' . "\n";
