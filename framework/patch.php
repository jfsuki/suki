<?php
$f = 'c:\laragon\www\suki\framework\app\Core\Agents\ConversationGateway.php';
$c = file_get_contents($f);

// Patch tokenizer stop words
$searchStr1 = '        $tokens = preg_split(\'/\s+/\', $text) ?: [];' . "\n" . '        return array_values(array_filter($tokens, fn($t) => mb_strlen($t, \'UTF-8\') >= 2));';

$replaceStr1 = '        $tokens = preg_split(\'/\s+/\', $text) ?: [];' . "\n" . '        $stops = [\'de\',\'la\',\'el\',\'los\',\'las\',\'un\',\'una\',\'unos\',\'unas\',\'y\',\'o\',\'en\',\'para\',\'por\',\'con\',\'su\',\'sus\',\'tu\',\'tus\',\'mi\',\'mis\',\'se\',\'del\',\'al\'];' . "\n" . '        return array_values(array_filter($tokens, fn($t) => mb_strlen($t, \'UTF-8\') >= 2 && !in_array($t, $stops, true)));';

$c = str_replace($searchStr1, $replaceStr1, $c);

// Patch negative reply handler
$searchStr2 = "                    if (\$commandName === 'CreateForm') {\n" .
    "                        \$entityName = \$this->normalizeEntityForSchema((string) (\$state['builder_pending_command']['entity'] ?? (\$state['entity'] ?? '')));\n" .
    "                        \$this->clearBuilderPendingCommand(\$state);\n" .
    "                        \$reply = 'Listo, no creo el formulario ' . (\$entityName !== '' ? (\$entityName . '.form') : 'pendiente') . '.' . \"\\n\"\n" .
    "                            . 'Dime si seguimos con la siguiente tabla o si quieres otro formulario.';\n" .
    "                        \$state = \$this->updateState(\$state, \$raw, \$reply, 'builder_next_step', \$entityName !== '' ? \$entityName : null, [], 'builder_onboarding');\n" .
    "                        \$this->saveState(\$tenantId, \$userId, \$state);\n" .
    "                        return \$this->result('ask_user', \$reply, null, null, \$state, \$this->telemetry('builder_confirm', true));\n" .
    "                    }";

$replaceStr2 = $searchStr2 . "\n" .
    "                    if (\$commandName === 'InstallPlaybook') {\n" .
    "                        \$this->clearBuilderPendingCommand(\$state);\n" .
    "                        \$reply = 'Entendido, no instalare esa plantilla.' . \"\\n\"\n" .
    "                            . 'Dime algo mas especifico de tu negocio para guiarte mejor.';\n" .
    "                        \$state['active_task'] = 'builder_onboarding';\n" .
    "                        \$state = \$this->updateState(\$state, \$raw, \$reply, 'builder_cancel', null, [], 'builder_onboarding');\n" .
    "                        \$this->saveState(\$tenantId, \$userId, \$state);\n" .
    "                        return \$this->result('ask_user', \$reply, null, null, \$state, \$this->telemetry('builder_confirm', true));\n" .
    "                    }";

$c = str_replace($searchStr2, $replaceStr2, $c);
file_put_contents($f, $c);
echo "Patched successfully!";
