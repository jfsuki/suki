<?php
$path = 'c:/laragon/www/suki/framework/contracts/agents/conversation_training_base.json';
$content = file_get_contents($path);
if ($content === false) { exit(1); }
if (strpos($content, '"capabilities"') === false) {
    $pattern = '/("app"\s*:\s*\{[\s\S]*?"examples"\s*:\s*\[[\s\S]*?\],)/';
    $insert = "$1\n                               \"capabilities\":  [\n                                                \"Crear y consultar datos por chat.\",\n                                                \"Guiarte paso a paso sin tecnicismos.\",\n                                                \"Mostrar reportes y totales cuando los pidas.\"\n                                            ],";
    $content = preg_replace($pattern, $insert, $content, 1);
    file_put_contents($path, $content);
}
?>
