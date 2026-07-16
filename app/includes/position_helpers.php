<?php
/**
 * 担当業務・ポジション入力の共通ヘルパー。
 *
 * DB上は既存の position 文字列を使い続け、画面入力とスコア計算時だけ
 * 「ホール・レジ」のような複数項目として扱う。
 */

/** 画面でチェック項目として表示する標準ポジション */
function positionPresetOptions(): array
{
    return ['ホール', 'レジ', 'キッチン', 'ドリンク', '洗い場'];
}

/** ポジション文字列を項目配列へ分解する */
function parsePositionItems(?string $position): array
{
    $text = trim((string) $position);
    if ($text === '') {
        return [];
    }

    $text = str_replace(["\r", "\n", "\t"], ' ', $text);
    $text = preg_replace('/\s*(?:、|,|，|・|／|\/|\||｜|＆|&|＋|\+)\s*/u', ',', $text);
    $text = preg_replace('/\s*と\s*/u', ',', $text);
    $text = preg_replace('/\s+/u', ',', $text);

    $items = [];
    foreach (explode(',', (string) $text) as $item) {
        $item = trim($item);
        if ($item === '') {
            continue;
        }
        $key = mb_strtolower($item);
        if (!isset($items[$key])) {
            $items[$key] = $item;
        }
    }

    return array_values($items);
}

/** チェック項目と自由入力を統合して保存用文字列を作る */
function buildPositionValue(array $selectedPositions, string $customPosition): string
{
    $items = [];

    foreach ($selectedPositions as $position) {
        foreach (parsePositionItems((string) $position) as $item) {
            $items[] = $item;
        }
    }
    foreach (parsePositionItems($customPosition) as $item) {
        $items[] = $item;
    }

    $uniqueItems = [];
    foreach ($items as $item) {
        $key = mb_strtolower($item);
        if (!isset($uniqueItems[$key])) {
            $uniqueItems[$key] = $item;
        }
    }

    return implode('・', array_values($uniqueItems));
}

/** 候補者の担当可能業務が、必要業務1件を満たしているか */
function positionCandidateCoversRequirement(string $candidateItem, string $requiredItem): bool
{
    $candidateItem = mb_strtolower(trim($candidateItem));
    $requiredItem = mb_strtolower(trim($requiredItem));

    if ($candidateItem === '' || $requiredItem === '') {
        return false;
    }

    return $candidateItem === $requiredItem || mb_strpos($candidateItem, $requiredItem) !== false;
}

/** 候補者の担当可能業務が、必要業務をすべて満たしているか */
function positionCandidateCoversAll(array $candidateItems, array $requiredItems): bool
{
    foreach ($requiredItems as $requiredItem) {
        $covered = false;
        foreach ($candidateItems as $candidateItem) {
            if (positionCandidateCoversRequirement($candidateItem, $requiredItem)) {
                $covered = true;
                break;
            }
        }
        if (!$covered) {
            return false;
        }
    }

    return true;
}

/** 候補者と必要業務に少なくとも一部の重なりがあるか */
function positionHasPartialOverlap(array $candidateItems, array $requiredItems): bool
{
    foreach ($candidateItems as $candidateItem) {
        foreach ($requiredItems as $requiredItem) {
            $candidate = mb_strtolower(trim($candidateItem));
            $required = mb_strtolower(trim($requiredItem));
            if (
                $candidate !== ''
                && $required !== ''
                && (mb_strpos($candidate, $required) !== false || mb_strpos($required, $candidate) !== false)
            ) {
                return true;
            }
        }
    }

    return false;
}
