<?php

$phpMailerCandidates = [
	__DIR__ . '/../vendor/autoload.php',
	__DIR__ . '/../../tienda_web/vendor/autoload.php',
	__DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
	__DIR__ . '/../../tienda_web/vendor/phpmailer/phpmailer/src/PHPMailer.php',
];

foreach ($phpMailerCandidates as $candidate) {
	if (!is_file($candidate)) {
		continue;
	}

	if (substr($candidate, -12) === '/autoload.php' || substr($candidate, -12) === '\\autoload.php') {
		require_once $candidate;
		return;
	}

	$baseDir = dirname($candidate);
	$requiredFiles = [
		$baseDir . '/PHPMailer.php',
		$baseDir . '/SMTP.php',
		$baseDir . '/Exception.php',
	];

	$allExist = true;
	foreach ($requiredFiles as $requiredFile) {
		if (!is_file($requiredFile)) {
			$allExist = false;
			break;
		}
	}

	if ($allExist) {
		foreach ($requiredFiles as $requiredFile) {
			require_once $requiredFile;
		}
		return;
	}
}
