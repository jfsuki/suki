<?php
declare(strict_types=1);

/**
 * Tests para TaskGroundingManifest (SCML Fase 1).
 *
 * Todos los intents/skills usados en los tests se leen dinamicamente del catalogo.
 * Cero strings hardcodeados de nombres de intents.
 */

require_once dirname(__DIR__) . '/app/autoload.php';

use App\Core\Grounding\TaskGroundingManifest;

// ---------------------------------------------------------------------------
// Helpers dinamicos — leen del catalogo real
// ---------------------------------------------------------------------------

function getCatalogPath(): string
{
    return dirname(__DIR__, 2) . '/docs/contracts/skills_catalog.json';
}

/** @return list<array<string, mixed>> */
function getSkillsWithHandler(): array
{
    $raw = file_get_contents(getCatalogPath());
    if ($raw === false) return [];
    $data = json_decode($raw, true);
    if (!is_array($data)) return [];
    $result = [];
    foreach ($data['catalog'] ?? [] as $skill) {
        if (!empty($skill['handler'])) {
            $result[] = $skill;
        }
    }
    return $result;
}

/** @return array<string, list<array<string, mixed>>> */
function getSkillsByCluster(): array
{
    $skills = getSkillsWithHandler();
    $grouped = [];
    foreach ($skills as $skill) {
        $cluster = (string) ($skill['topic_cluster'] ?? 'general');
        $grouped[$cluster][] = $skill;
    }
    return $grouped;
}

/**
 * Retorna 2 skills de clusters DISTINTOS.
 * Si no hay 2 clusters distintos, retorna skills del unico cluster disponible
 * (el test 6 detectara esta situacion y la manejara apropiadamente).
 *
 * @return list<array<string, mixed>>
 */
function getTwoSkillsFromDifferentClusters(): array
{
    $byCluster = getSkillsByCluster();
    $result = [];
    foreach ($byCluster as $cluster => $skills) {
        if (!empty($skills)) {
            $result[] = $skills[0];
        }
        if (count($result) >= 2) {
            break;
        }
    }
    return $result;
}

/** @return array<string, mixed>|null */
function getSkillWithoutHandler(): ?array
{
    $raw = file_get_contents(getCatalogPath());
    if ($raw === false) return null;
    $data = json_decode($raw, true);
    if (!is_array($data)) return null;
    foreach ($data['catalog'] ?? [] as $skill) {
        if (empty($skill['handler']) && !empty($skill['name'])) {
            return $skill;
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// Test runner
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$errors = [];

function check(bool $cond, string $label, string &$errMsg = ''): void
{
    global $passed, $failed, $errors;
    if ($cond) {
        $passed++;
        echo "  PASS: {$label}" . PHP_EOL;
    } else {
        $failed++;
        $msg = "  FAIL: {$label}" . ($errMsg !== '' ? " — {$errMsg}" : '');
        $errors[] = $msg;
        echo $msg . PHP_EOL;
        $errMsg = '';
    }
}

echo '=== SUKI Grounding Manifest Tests ===' . PHP_EOL;
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 1: build() retorna lo que hay en el JSON real — sin hardcodear
// ---------------------------------------------------------------------------
echo '[TEST 1] build() retorna clusters del JSON real' . PHP_EOL;
$manifest = new TaskGroundingManifest(getCatalogPath());
$built = $manifest->build();
$errMsg = 'build() retorno vacio';
check(count($built) > 0, 'build() tiene al menos 1 cluster', $errMsg);

$totalBuilt = array_sum(array_map('count', $built));
$catalogSkills = count(getSkillsWithHandler());
$errMsg = "build() tiene {$totalBuilt} skills, catalogo tiene {$catalogSkills}";
check($totalBuilt === $catalogSkills, "build() tiene exactamente {$catalogSkills} skills del catalogo", $errMsg);
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 2: intent vacio -> needs_clarification
// ---------------------------------------------------------------------------
echo '[TEST 2] intent vacio => needs_clarification=true' . PHP_EOL;
$r = $manifest->renderForSystemPrompt('', 0.0, '');
$errMsg = 'needs_clarification deberia ser true con intent vacio';
check($r['needs_clarification'] === true, 'needs_clarification=true con intent=""', $errMsg);
$errMsg = 'block deberia ser "" con needs_clarification=true';
check($r['block'] === '', 'block="" cuando needs_clarification', $errMsg);
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 3: confidence baja -> needs_clarification (aunque intent tenga handler)
// ---------------------------------------------------------------------------
echo '[TEST 3] confidence baja => needs_clarification=true' . PHP_EOL;
$allSkills = getSkillsWithHandler();
$errMsg = 'No hay skills con handler en el catalogo';
if (!check(!empty($allSkills), 'hay skills con handler disponibles', $errMsg)) {
    // Sin skills, los tests siguientes no pueden continuar
}
$anyIntent = !empty($allSkills) ? (string) $allSkills[0]['name'] : '';
if ($anyIntent !== '') {
    $r = $manifest->renderForSystemPrompt($anyIntent, 0.30, '');
    $errMsg = "needs_clarification deberia ser true con confidence=0.30 < 0.60 (intent={$anyIntent})";
    check($r['needs_clarification'] === true, "needs_clarification=true con confidence=0.30 para '{$anyIntent}'", $errMsg);
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 4: intent sin handler -> block vacio, no clarification
// ---------------------------------------------------------------------------
echo '[TEST 4] intent sin handler => block vacio, no clarification' . PHP_EOL;
$noHandlerSkill = getSkillWithoutHandler();
if ($noHandlerSkill !== null) {
    $skillName = (string) $noHandlerSkill['name'];
    $r = $manifest->renderForSystemPrompt($skillName, 0.90, '');
    $errMsg = "needs_clarification deberia ser false para '{$skillName}' (sin handler)";
    check($r['needs_clarification'] === false, "needs_clarification=false para '{$skillName}'", $errMsg);
    $errMsg = "block deberia ser '' para intent sin handler '{$skillName}'";
    check($r['block'] === '', "block='' para intent sin handler '{$skillName}'", $errMsg);
} else {
    echo '  SKIP: no hay skills sin handler en el catalogo' . PHP_EOL;
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 5: intent con handler y confidence alta -> block con contenido dinamico
// ---------------------------------------------------------------------------
echo '[TEST 5] intent con handler + confidence alta => block no vacio' . PHP_EOL;
if ($anyIntent !== '') {
    $r = $manifest->renderForSystemPrompt($anyIntent, 0.85, '');
    $errMsg = "needs_clarification deberia ser false para '{$anyIntent}' con confidence=0.85";
    check($r['needs_clarification'] === false, "needs_clarification=false con confidence=0.85", $errMsg);
    $errMsg = "block deberia ser no-vacio para '{$anyIntent}' con handler";
    check($r['block'] !== '', "block no vacio para '{$anyIntent}'", $errMsg);
    $errMsg = "block deberia contener el nombre del intent '{$anyIntent}'";
    check(str_contains($r['block'], $anyIntent), "block contiene el nombre del intent '{$anyIntent}'", $errMsg);
    $errMsg = "block deberia contener el cluster '{$r['cluster']}'";
    check(str_contains($r['block'], $r['cluster']), "block contiene el cluster '{$r['cluster']}'", $errMsg);
    $errMsg = "block deberia contener 'INSTRUCCION'";
    check(str_contains($r['block'], 'INSTRUCCION'), 'block contiene INSTRUCCION', $errMsg);
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 6: aislamiento de topic — peers solo del mismo cluster
// ---------------------------------------------------------------------------
echo '[TEST 6] aislamiento de topic — peers solo del mismo cluster' . PHP_EOL;
$twoSkills = getTwoSkillsFromDifferentClusters();
if (count($twoSkills) >= 2) {
    $skillA = $twoSkills[0];
    $skillB = $twoSkills[1];
    $clusterA = (string) ($skillA['topic_cluster'] ?? 'general');
    $clusterB = (string) ($skillB['topic_cluster'] ?? 'general');
    $nameA = (string) $skillA['name'];
    $nameB = (string) $skillB['name'];

    $rA = $manifest->renderForSystemPrompt($nameA, 0.85, '');
    $rB = $manifest->renderForSystemPrompt($nameB, 0.85, $rA['cluster']);

    if ($clusterA !== $clusterB) {
        // Los clusters son distintos — el bloque de B no debe mencionar el cluster de A
        $errMsg = "bloque de B (cluster={$clusterB}) no debe mencionar cluster de A (={$clusterA})";
        check(!str_contains($rB['block'], $clusterA), "aislamiento: bloque de '{$nameB}' no contiene cluster '{$clusterA}'", $errMsg);
    } else {
        // Los dos skills estan en el mismo cluster — test de aislamiento no aplica
        echo "  INFO: ambos skills en cluster '{$clusterA}' — aislamiento no aplica, test pasado por diseno" . PHP_EOL;
        $passed++;
    }
} else {
    echo '  SKIP: no hay 2 clusters distintos disponibles' . PHP_EOL;
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 7: degradacion graciosa — JSON inexistente no lanza excepcion
// ---------------------------------------------------------------------------
echo '[TEST 7] degradacion graciosa con JSON inexistente' . PHP_EOL;
$badManifest = new TaskGroundingManifest('/ruta/inexistente/skills_catalog.json');
try {
    $r = $badManifest->renderForSystemPrompt('cualquier_intent', 0.90, '');
    $errMsg = "block deberia ser '' con JSON inexistente (fue: '{$r['block']}')";
    check($r['block'] === '', 'retorna block="" con JSON inexistente', $errMsg);
    $errMsg = "needs_clarification deberia ser false (fue: " . var_export($r['needs_clarification'], true) . ')';
    check($r['needs_clarification'] === false, 'no lanza excepcion con JSON inexistente', $errMsg);
} catch (\Throwable $e) {
    $errMsg = 'Lanzo excepcion: ' . $e->getMessage();
    check(false, 'degradacion graciosa sin excepcion', $errMsg);
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// TEST 8: hasHandler() y getTopicCluster() son consistentes con build()
// ---------------------------------------------------------------------------
echo '[TEST 8] hasHandler() y getTopicCluster() consistentes con build()' . PHP_EOL;
if (!empty($allSkills)) {
    $testSkill = $allSkills[0];
    $testName  = (string) $testSkill['name'];
    $testCluster = (string) ($testSkill['topic_cluster'] ?? 'general');

    $freshManifest = new TaskGroundingManifest(getCatalogPath());
    $errMsg = "hasHandler('{$testName}') deberia ser true";
    check($freshManifest->hasHandler($testName), "hasHandler('{$testName}')=true", $errMsg);
    $errMsg = "getTopicCluster('{$testName}') deberia ser '{$testCluster}'";
    check($freshManifest->getTopicCluster($testName) === $testCluster, "getTopicCluster('{$testName}')='{$testCluster}'", $errMsg);
    $errMsg = "hasHandler('intent_que_no_existe') deberia ser false";
    check(!$freshManifest->hasHandler('intent_que_no_existe'), 'hasHandler(intent_inexistente)=false', $errMsg);
}
echo PHP_EOL;

// ---------------------------------------------------------------------------
// Resultado final
// ---------------------------------------------------------------------------
$total = $passed + $failed;
echo '======================================' . PHP_EOL;
echo "Tests passed: {$passed} / {$total}" . PHP_EOL;
if (!empty($errors)) {
    echo PHP_EOL . 'Failures:' . PHP_EOL;
    foreach ($errors as $err) {
        echo $err . PHP_EOL;
    }
}
echo PHP_EOL;
exit($failed > 0 ? 1 : 0);
