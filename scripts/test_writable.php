<?php
// Simple writable diagnostics for template storage directories.
function line($label, $value) {
	echo str_pad($label, 28).': '.var_export($value, true)."\n";
}

$base = realpath(__DIR__.'/../files');
$tplDir = $base.'/templates';
line('Base exists', $base && is_dir($base));
line('Base is_writable', is_writable($base));
line('Templates exists', is_dir($tplDir));
line('Templates is_writable', is_writable($tplDir));

$probe = $tplDir.'/__probe_'.getmypid().'_'.time().'.txt';
$written = @file_put_contents($probe, 'probe '.time());
line('file_put_contents return', $written);
line('Probe exists', file_exists($probe));
if (file_exists($probe)) {
	$removed = @unlink($probe);
	line('Probe removed', $removed);
}

// Attempt directory create inside templates (org id simulation) if not existing
$sim = $tplDir.'/__sim_org';
if (!is_dir($sim)) {
	$mk = @mkdir($sim, 0775, true);
	line('mkdir __sim_org', $mk);
}
line('__sim_org is_writable', is_dir($sim) ? is_writable($sim) : null);
