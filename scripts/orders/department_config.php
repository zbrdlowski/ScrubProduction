<?php
declare(strict_types=1);

/**
 * DEPARTMENT & SUBCATEGORY CLASSIFICATION CONFIG
 * ================================================
 * Tento súbor je jediné miesto kde sa definuje mapovanie custom_label prefixov
 * na departmenty a podkategórie. Upravuj tu — nikde inde.
 *
 * PRAVIDLÁ:
 *  - Prefix = všetko pred prvým podtržítkom (underscore) v custom_label / SKU
 *  - Jeden prefix môže patriť viacerým departmentom (napr. GFP_ → G + P + F)
 *  - Subcategory je podkategória v rámci departmentu Graphics
 *  - Upsellové položky (auto-generated) sa riadia vlastnou logikou nižšie
 *
 * DEPARTMENTS:
 *   G = Graphics
 *   P = Plastics
 *   S = Seat Cover
 *   F = Fitting
 */

// ---------------------------------------------------------------------------
// 1.  PREFIX → DEPARTMENT(Y) MAPPING
//     Kľúč = prefix (uppercase, bez podtržítka)
//     Hodnota = pole department kódov
// ---------------------------------------------------------------------------
const DEPT_PREFIX_MAP = [
  // --- Pure Graphics ---
  'G'     => ['G'],
  'TF'    => ['G','F'],          // TF bez P (len grafika, nie plasty)

  // --- Pure Plastics ---
  'P'     => ['P'],
  'PS'    => ['P', 'S'],     // Plastics + Seat Cover
  'PF'    => ['P', 'F'],     // Plastics + Fitting

  // --- Pure Seat Cover ---
  'S'     => ['S'],
  'GS'    => ['G', 'S'],     // Graphics + Seat Cover

  // --- Graphics + Fitting (bez plastov) ---
  // (tu žiaden príklad zatiaľ — miesto pre budúce prefixe)

  // --- Graphics + Plastics + Fitting (kompletné sety) ---
  'GFP'   => ['G', 'P', 'F'],
  'GFPS'  => ['G', 'P', 'F', 'S'],

  // --- Transfer Foil sets (Graphics + Plastics) ---
  'TFP'   => ['G', 'P','F'],
  'TFPS'  => ['G', 'P', 'S','F'],

  // ---------------------------------------------------------------------------
  // DOPLŇ SEM nové prefixe v prípade zmeny sortimentu.
  // Formát:  'PREFIX' => ['X', 'Y', ...],
  // ---------------------------------------------------------------------------
];

// ---------------------------------------------------------------------------
// 2.  GRAPHICS SUBCATEGORIES
//     Položky, ktorých item_type_code je 'G', môžu mať podkategóriu.
//     Kľúč = prefix custom_label (uppercase, bez podtržítka)
//     Hodnota = subcategory kód (interný, pre zobrazenie v UI)
//
//     Poznámka: tieto prefixe musia byť PODMNOŽINOU prefixov mapovaných
//     na ['G', ...] vyššie. Definuješ tu len podkategóriu, nie department.
// ---------------------------------------------------------------------------
const GRAPHICS_SUBCAT_PREFIX_MAP = [
  'G_MF'      => 'MID_FORKS',     // Mid Forks
  'G_RT'      => 'RIM_TAPES',     // Rim Tapes
  'G_SPOKE'   => 'SPOKE_COATS',   // Spoke Coats
  'G_MC'      => 'MOTO_CARPET',   // Moto Carpet / Bike Mat (koberec s logami tímu)
  'G_STICKERS'=> 'STICKERS',      // Rôzne nálepky
  'G_STAND'   => 'STAND',         // Stand graphics
  'G_4PCS'    => '4_PCS_FORK_STICKERS',      // Štvorpárové nálepky (štandardné sú jednopárové, takže špeciálny prefix)
  'G_T-SHIRT' => 'T_SHIRT',       // Tričká 
  'G_HAT'     => 'HAT',           // Čapice
  'G_HOODIE'  => 'HOODIE',        // Mikiny
  // ---------------------------------------------------------------------------
  // DOPLŇ SEM nové grafické podkategórie.
  // Formát:  'G_XYZ' => 'SUBCAT_CODE',
  // ---------------------------------------------------------------------------
];

// Ľudsky čitateľné labely pre subcategory kódy (pre UI)
const GRAPHICS_SUBCAT_LABELS = [
  'MID_FORKS'   => 'Mid Forks',
  'RIM_TAPES'   => 'Rim Tapes',
  'SPOKE_COATS' => 'Spoke Coats',
  'MOTO_CARPET' => 'Moto Carpet',
  'STICKERS'    => 'Stickers',
  'STAND'       => 'Stand',
  '4_PCS_FORK_STICKERS' => '4 PCS Fork Stickers',
  'T_SHIRT' => 'T-Shirt',
  'HAT' => 'Hat',
  'HOODIE' => 'Hoodie',
];

// ---------------------------------------------------------------------------
// 3.  UPSELL / AUTO-GENERATED ITEMS
//     Tieto položky sa vytvárajú automaticky pri importe na základe
//     checkboxov zákazníka (Shoptet variant fields).
//     Nemajú vlastné options formuláre v detaile objednávky.
//     Definícia tu slúži len ako dokumentácia — samotná logika je v importeri.
// ---------------------------------------------------------------------------
const UPSELL_AUTO_TAGS = [
  // Auto-tag (v options_json._auto_generated) => popis
  'SHOPTET_AUTO_FITTING'   => 'Applying/Fitting grafiky (z checkboxu)',
  'SHOPTET_AUTO_SEATCOVER' => 'Seat Cover upsell (z checkboxu)',
  'SHOPTET_AUTO_MIDFORKS'  => 'Mid-Forks upsell (z checkboxu)',
  'SHOPTET_AUTO_GRIP'      => 'Grip Stickers upsell (z checkboxu)',
  'GFP_AUTO_PLASTICS'      => 'Auto-plasty z GFP setu',
  'GFP_AUTO_FITTING'       => 'Auto-fitting z GFP setu',
  'SEAT_PATCH_AUTO_GRAPHICS' => 'Auto-patch grafika zo Seat Cover',
];

// Tieto auto-tagy NEMAJÚ zobraziť options formulár v detaile objednávky
const UPSELL_NO_OPTIONS_FORM_TAGS = [
  // 'SHOPTET_AUTO_MIDFORKS',
  //'SHOPTET_AUTO_SEATCOVER',
  'SHOPTET_AUTO_FITTING',
  'SHOPTET_AUTO_GRIP',
];

// ---------------------------------------------------------------------------
// 4.  POMOCNÉ FUNKCIE
// ---------------------------------------------------------------------------

/**
 * Extrahuje prefix z custom_label alebo SKU.
 * Vracia uppercase string pred prvým '_', alebo null ak nenájde.
 *
 * Príklady:
 *   "G_ST00009"        → "G"
 *   "GFP_SE1047_LE"    → "GFP"
 *   "G_MF_123"         → "G_MF"   ← špeciálny prípad pre subcategory detekciu
 *   "TFPS_XYZ"         → "TFPS"
 */
function dept_extract_prefix(?string $value): ?string {
  $value = trim((string)$value);
  if ($value === '') return null;
  // Vezmeme len časť pred prvým '|' (eBay má "GFP_SE1086_R3 | EXACT YEAR W")
  $value = explode('|', $value)[0];
  $value = strtoupper(trim($value));
  if (preg_match('/^([A-Z][A-Z0-9]*(?:_[A-Z][A-Z0-9]*)?)_/', $value, $m)) {
    // Pokus o dvojslovný prefix (napr. G_MF) — pre subcategory detekciu
    return $m[1];
  }
  if (preg_match('/^([A-Z][A-Z0-9]*)$/', $value, $m)) {
    return $m[1]; // Celý string je prefix (napr. "GFP" bez ďalšieho)
  }
  return null;
}

/**
 * Vráti zoznam department kódov pre daný custom_label / SKU.
 * Používa DEPT_PREFIX_MAP. Fallback na starú heuristiku ak prefix neznámy.
 *
 * @return string[]  Napr. ['G', 'P', 'F']
 */
function dept_get_departments(?string $customLabel, ?string $sku = null): array {
  foreach ([$customLabel, $sku] as $candidate) {
    $fullPrefix = dept_extract_prefix($candidate);
    if ($fullPrefix === null) continue;

    // Najprv skús celý dvojslovný prefix (G_MF → subcategory, ale stále G dept)
    // Rozlož na jednoduchý prefix (pred prvým _)
    $simplePrefix = explode('_', $fullPrefix)[0];

    if (isset(DEPT_PREFIX_MAP[$fullPrefix])) {
      return DEPT_PREFIX_MAP[$fullPrefix];
    }
    if (isset(DEPT_PREFIX_MAP[$simplePrefix])) {
      return DEPT_PREFIX_MAP[$simplePrefix];
    }
  }
  return [];
}

/**
 * Vráti subcategory kód pre Graphics položky, alebo null.
 * Používa GRAPHICS_SUBCAT_PREFIX_MAP.
 */
function dept_get_graphics_subcat(?string $customLabel, ?string $sku = null): ?string {
  foreach ([$customLabel, $sku] as $candidate) {
    $fullPrefix = dept_extract_prefix($candidate);
    if ($fullPrefix === null) continue;
    if (isset(GRAPHICS_SUBCAT_PREFIX_MAP[$fullPrefix])) {
      return GRAPHICS_SUBCAT_PREFIX_MAP[$fullPrefix];
    }
  }
  return null;
}

/**
 * Vráti či má daná položka zobraziť options formulár v detaile objednávky.
 * Upsellové auto-generované položky formulár NEMAJÚ.
 */
function dept_has_options_form(?string $optionsJson): bool {
  if ($optionsJson === null) return true;
  $decoded = json_decode($optionsJson, true);
  if (!is_array($decoded)) return true;
  $autoTag = $decoded['_auto_generated'] ?? null;
  if ($autoTag === null) return true;
  return !in_array($autoTag, UPSELL_NO_OPTIONS_FORM_TAGS, true);
}

/**
 * Konvertuje zoznam department kódov na category DB kódy.
 * Zrkadlí logiku pôvodnej oi_item_type_to_category_codes() ale cez config.
 *
 * @param  string[] $departments  Napr. ['G', 'P', 'F']
 * @return string[]               Napr. ['GRAPHICS', 'PLASTICS', 'FITTING']
 */
function dept_to_category_codes(array $departments): array {
  $map = [
    'G' => 'GRAPHICS',
    'P' => 'PLASTICS',
    'S' => 'SEATCOVER',
    'F' => 'FITTING',
  ];
  $out = [];
  foreach ($departments as $d) {
    if (isset($map[$d])) $out[] = $map[$d];
  }
  return array_values(array_unique($out));
}
