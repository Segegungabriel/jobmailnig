<?php
$jobs = json_decode(file_get_contents("data/jobs.json"), true);
$job = array_filter($jobs, fn($j) => $j['id'] == $_GET['id'])[0];

$whatsapp_link = "https://wa.me/{$job['contact']}?text=Hello, I'm interested in the {$job['title']} position.";
$telegram_link = "https://t.me/{$job['contact']}?text=Hello, I'm interested in the {$job['title']} position.";
?>
<a href="<?= $whatsapp_link ?>">Apply via WhatsApp</a>
<a href="<?= $telegram_link ?>">Apply via Telegram</a>
