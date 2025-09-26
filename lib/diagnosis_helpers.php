<?php
declare(strict_types=1);


function loadSymptomsByCategory(PDO $pdo): array {
    $cats = $pdo->query("SELECT id, code, description FROM symptom_categories ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($cats as $c) $map[$c['id']] = ['meta'=>$c, 'items'=>[]];

    $stm = $pdo->query("SELECT id, slug, label, category_id FROM symptoms WHERE is_active=1 ORDER BY category_id, label");
    $symptoms = $stm->fetchAll(PDO::FETCH_ASSOC);
    foreach ($symptoms as $s) {
        $cid = (int)$s['category_id'];
        if (!isset($map[$cid])) $map[$cid] = ['meta'=>['id'=>$cid,'code'=>'?','description'=>null], 'items'=>[]];
        $map[$cid]['items'][] = $s;
    }
    return $map;
}

function loadDiseasesAndRules(PDO $pdo): array {
    $dRows = $pdo->query("SELECT id, code, name, description, threshold FROM diseases ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    $diseases = [];
    foreach ($dRows as $d) $diseases[(int)$d['id']] = $d;

    $rules = [];
    $rRows = $pdo->query("SELECT disease_id, symptom_id, weight FROM disease_rule_symptoms ORDER BY disease_id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rRows as $r) {
        $did = (int)$r['disease_id'];
        $sid = (int)$r['symptom_id'];
        $w = (int)$r['weight'];
        $rules[$did]['map'][$sid] = $w;
        $rules[$did]['total'] = ($rules[$did]['total'] ?? 0) + $w;
    }
    return ['diseases'=>$diseases, 'rules'=>$rules];
}

/**
 * @param array $diseases keyed by id
 * @param array $rules keyed by disease id with ['map'=>[symptom=>weight], 'total'=>int]
 * @param array $selectedSymptomIds plain array of ints
 * @return array per disease keyed by disease id with fields matched_weight, total_weight, confidence, threshold, name, code
 */
function computeConfidences(array $diseases, array $rules, array $selectedSymptomIds): array {
    $selected = array_flip(array_map('intval', $selectedSymptomIds));
    $out = [];
    foreach ($diseases as $did => $d) {
        $total = (int)($rules[$did]['total'] ?? 0);
        $matched = 0;
        $map = $rules[$did]['map'] ?? [];
        if (!empty($map)) {
            foreach ($map as $sid => $w) {
                if (isset($selected[$sid])) $matched += (int)$w;
            }
        }
        $confidence = $total > 0 ? round(($matched / $total) * 100.0, 2) : 0.0;
        $out[$did] = [
            'disease_id' => $did,
            'code' => $d['code'] ?? '',
            'name' => $d['name'] ?? '',
            'matched_weight' => $matched,
            'total_weight' => $total,
            'confidence' => $confidence,
            'threshold' => (int)($d['threshold'] ?? 0),
        ];
    }
    return $out;
}

function decidePrimary(array $perDisease): array {
    $primary = null;
    $bestConf = -1.0;
    foreach ($perDisease as $did => $data) {
        if ($data['confidence'] > $bestConf) {
            $bestConf = $data['confidence'];
            $primary = $did;
        }
    }
    if ($primary === null) return ['primary'=>null, 'confidence'=>0.0];
    $threshold = (int)$perDisease[$primary]['threshold'];
    if ($perDisease[$primary]['confidence'] >= $threshold) {
        return ['primary'=>$primary, 'confidence'=>round($perDisease[$primary]['confidence'],2)];
    }
    return ['primary'=>null, 'confidence'=>round($perDisease[$primary]['confidence'],2)];
}

function loadRecommendedDrugsMap(PDO $pdo, array $diseaseIds): array {
    $diseaseIds = array_values(array_unique(array_filter(array_map('intval', $diseaseIds))));
    if (empty($diseaseIds)) return [];
    $place = implode(',', array_fill(0, count($diseaseIds), '?'));
    $sql = "SELECT ddr.disease_id, dr.id AS drug_id, dr.name, dr.code, dr.unit
            FROM disease_recommended_drugs ddr
            JOIN drugs dr ON dr.id = ddr.drug_id
            WHERE ddr.disease_id IN ($place)
            ORDER BY dr.name";
    $stm = $pdo->prepare($sql);
    $stm->execute($diseaseIds);
    $rows = $stm->fetchAll(PDO::FETCH_ASSOC);
    $map = [];
    foreach ($rows as $r) {
        $map[(int)$r['disease_id']][] = $r;
    }
    return $map;
}

function saveDiagnosis(PDO $pdo, int $patientId, int $evaluatorId, array $payloadObj, string $resultText, ?int $resultDiseaseId, float $confidence, ?string $notes = null): int {
    $payloadJson = json_encode($payloadObj, JSON_UNESCAPED_UNICODE);
    $raw = random_bytes(16);
    $raw[6] = chr((ord($raw[6]) & 0x0f) | 0x40);
    $raw[8] = chr((ord($raw[8]) & 0x3f) | 0x80);
    $uuid = vsprintf('%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x', str_split($raw, 1));

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare("INSERT INTO diagnoses (uuid, patient_id, evaluator_user_id, payload, result, result_disease_id, confidence, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        $ins->execute([$uuid, $patientId, $evaluatorId, $payloadJson, $resultText, $resultDiseaseId ?: null, number_format($confidence,2,'.',''), $notes]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function ensurePatientExists(PDO $pdo, int $patientId): bool {
    $stm = $pdo->prepare("SELECT id FROM patients WHERE id = ? LIMIT 1");
    $stm->execute([$patientId]);
    return (bool)$stm->fetchColumn();
}
