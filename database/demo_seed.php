<?php
/**
 * Demo portfolio — realistic data so every screen has something to show.
 * Three properties (apartments, offices, townhouses for sale), owners incl. a block owner and co-owners,
 * 25+ billing accounts, six months of invoices, meter readings, M-Pesa / bank / cash payments (some partial,
 * some overpaid, one unmatched), deposits, expenses, remittances, tickets, leads & deals, short-stay bookings,
 * and active self-service portal logins.
 */
if (!defined('VK_ROOT')) exit;
mt_srand(2026);
$t0 = microtime(true);
$say = fn(string $m) => print("  · $m\n");
$PW = password_hash('Vikao@2026', PASSWORD_DEFAULT);
$PORTAL_PW = password_hash('Portal@2026', PASSWORD_DEFAULT);
$role = fn(string $c) => (int)val("SELECT id FROM sys_roles WHERE code = ?", [$c]);
$bt = fn(string $c) => (int)bill_type_by_code($c)['id'];
$today = date('Y-m-d');

/* ---------------- staff ---------------- */
$S = [];
foreach ([['admin', 'System', 'Admin', 'admin@vikao.io', 'super_admin', 'Managing Director', 'Admin@2026'], ['grace', 'Grace', 'Muthoni', 'pm@vikao.io', 'property_manager', 'Property Manager'],
          ['daniel', 'Daniel', 'Kiprop', 'accounts@vikao.io', 'accountant', 'Finance Lead'], ['joseph', 'Joseph', 'Mwangi', 'reader@vikao.io', 'meter_reader', 'Meter Reader'],
          ['faith', 'Faith', 'Achieng', 'care@vikao.io', 'crm_agent', 'Customer Care'], ['brian', 'Brian', 'Kamau', 'sales@vikao.io', 'sales_agent', 'Sales Executive'],
          ['lucy', 'Lucy', 'Njeri', 'stays@vikao.io', 'sst_manager', 'Short Stay Manager'], ['kevin', 'Kevin', 'Omondi', 'market@vikao.io', 'ecom_admin', 'Marketplace Admin']] as $u) {
    $S[$u[0]] = insert('sys_users', ['uuid' => uuid4(), 'role_id' => $role($u[4]), 'first_name' => $u[1], 'last_name' => $u[2], 'email' => $u[3], 'username' => $u[0], 'job_title' => $u[5],
        'phone' => '+2547' . mt_rand(10000000, 99999999), 'password_hash' => isset($u[6]) ? password_hash($u[6], PASSWORD_DEFAULT) : $PW, 'must_change_password' => 0]);
}
$say('8 staff users');

/* ---------------- helpers ---------------- */
$person = function (string $first, string $last, array $x = []) {
    $email = strtolower($first . '.' . $last) . '@example.com';
    return party_create($x + ['party_type' => 'individual', 'first_name' => $first, 'last_name' => $last, 'email' => $email, 'phone' => '07' . mt_rand(10, 29) . mt_rand(100000, 999999),
        'id_type' => 'national_id', 'id_number' => (string)mt_rand(20000000, 39999999), 'kra_pin' => 'A00' . mt_rand(1000000, 9999999) . 'Z', 'gender' => $x['gender'] ?? null,
        'occupation' => $x['occupation'] ?? null, 'next_of_kin_name' => $x['nok'] ?? null, 'next_of_kin_phone' => isset($x['nok']) ? '07' . mt_rand(10000000, 29999999) : null]);
};
$company = fn(string $name, array $x = []) => party_create($x + ['party_type' => 'company', 'company_name' => $name, 'email' => 'accounts@' . strtolower(preg_replace('/[^a-z]/i', '', explode(' ', $name)[0])) . '.co.ke',
    'phone' => '020' . mt_rand(2000000, 7999999), 'id_type' => 'company_reg', 'id_number' => 'PVT-' . strtoupper(random_code(6)), 'kra_pin' => 'P05' . mt_rand(1000000, 9999999) . 'X']);
$makeProperty = function (array $p) {
    $id = insert('prpt_properties', $p + ['uuid' => uuid4(), 'status' => 'active', 'country_code' => 'KE', 'county' => 'Nairobi']);
    foreach (col("SELECT id FROM fin_accounts WHERE is_master = 1 AND account_type IN ('mpesa_paybill','bank')") as $i => $acc) insert('fin_property_accounts', ['property_id' => $id, 'account_id' => $acc, 'account_reference_format' => '{unit_no}', 'is_default' => (int)($i === 0)]);
    return $id;
};
$makeUnits = function (int $pid, array $blocks, int $f0, int $f1, int $per, callable $attrs) {
    $ids = [];
    foreach ($blocks as $b) {
        $bid = $b ? insert('prpt_blocks', ['property_id' => $pid, 'code' => $b, 'name' => 'Block ' . $b, 'floors_count' => $f1 - $f0 + 1, 'sort_order' => ord($b)]) : null;
        for ($f = $f0; $f <= $f1; $f++) {
            $fid = insert('prpt_floors', ['property_id' => $pid, 'block_id' => $bid, 'level' => $f, 'name' => $f === 0 ? 'Ground floor' : ordinal($f) . ' floor']);
            for ($n = 1; $n <= $per; $n++) {
                $no = ($b ? $b . '-' : '') . ($f === 0 ? 'G' : $f) . str_pad((string)$n, 2, '0', STR_PAD_LEFT);
                $ids[$no] = insert('prpt_units', $attrs($f, $n, $no) + ['uuid' => uuid4(), 'property_id' => $pid, 'block_id' => $bid, 'floor_id' => $fid, 'unit_no' => $no, 'status' => 'vacant', 'deposit_months' => 1]);
            }
        }
    }
    return $ids;
};
$own = fn(int $party, string $scope, int $pid, ?int $block, ?int $unit, float $share = 100, string $since = '2022-01-01', string $acq = 'purchase') =>
    insert('prpt_ownerships', ['party_id' => $party, 'scope' => $scope, 'property_id' => $pid, 'block_id' => $block, 'unit_id' => $unit, 'share_pct' => $share, 'acquisition' => $acq, 'start_date' => $since, 'is_primary_contact' => 1]);

/* ---------------- properties ---------------- */
$KH = $makeProperty(['code' => 'KH', 'name' => 'Kilimani Heights', 'property_type' => 'residential', 'management_type' => 'full', 'area' => 'Kilimani', 'address' => 'Argwings Kodhek Road', 'lr_number' => '209/12345',
    'year_built' => 2019, 'invoice_day' => 1, 'due_day' => 5, 'manager_user_id' => $S['grace'], 'caretaker_name' => 'Hassan Ali', 'caretaker_phone' => '0722111222',
    'description' => 'Two-block residential development with gym, pool, borehole backup and 24/7 security. 2- and 3-bedroom apartments.']);
$WBP = $makeProperty(['code' => 'WBP', 'name' => 'Westlands Business Park', 'property_type' => 'commercial', 'management_type' => 'full', 'area' => 'Westlands', 'address' => 'Waiyaki Way', 'lr_number' => '1870/IV/88',
    'year_built' => 2016, 'invoice_day' => 1, 'due_day' => 7, 'manager_user_id' => $S['grace'], 'description' => 'Grade-A office block with backup power, fibre and basement parking.']);
$KG = $makeProperty(['code' => 'KG', 'name' => 'Kitengela Gardens', 'property_type' => 'residential', 'management_type' => 'sales_only', 'county' => 'Kajiado', 'area' => 'Kitengela', 'address' => 'Namanga Road',
    'year_built' => 2026, 'invoice_day' => 1, 'due_day' => 5, 'description' => 'Gated community of 4-bedroom townhouses — off-plan and ready units for sale.']);

$khU = $makeUnits($KH, ['A', 'B'], 0, 3, 4, fn($f, $n, $no) => ['unit_type' => 'apartment', 'usage_class' => 'residential', 'purpose' => in_array($no, ['A-301', 'A-302'], true) ? 'short_stay' : 'rent',
    'bedrooms' => $f >= 2 ? 3 : 2, 'bathrooms' => $f >= 2 ? 2 : 2, 'size_sqm' => $f >= 2 ? 145 : 110, 'rent_amount' => $f >= 2 ? 65000 : 45000, 'service_charge' => $f >= 2 ? 8000 : 6000,
    'furnishing' => in_array($no, ['A-301', 'A-302'], true) ? 'furnished' : 'unfurnished', 'sale_price' => $f >= 2 ? 16500000 : 12500000]);
$wbU = $makeUnits($WBP, [null], 1, 3, 3, fn($f, $n, $no) => ['unit_type' => 'office', 'usage_class' => 'commercial', 'purpose' => 'rent', 'size_sqm' => 180 + $n * 20, 'rent_amount' => 110000 + $n * 15000, 'service_charge' => 15000 + $n * 1000]);
$kgU = []; $fid = insert('prpt_floors', ['property_id' => $KG, 'level' => 0, 'name' => 'Ground']);
for ($i = 1; $i <= 8; $i++) $kgU['TH-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT)] = insert('prpt_units', ['uuid' => uuid4(), 'property_id' => $KG, 'floor_id' => $fid, 'unit_no' => 'TH-' . str_pad((string)$i, 2, '0', STR_PAD_LEFT), 'unit_type' => 'townhouse', 'usage_class' => 'residential',
    'purpose' => 'sale', 'bedrooms' => 4, 'bathrooms' => 3, 'size_sqm' => 220, 'sale_price' => 18500000 + ($i % 3) * 750000, 'service_charge' => 7500, 'status' => 'vacant', 'deposit_months' => 0]);
$U = $khU + $wbU + $kgU;
$blockA = (int)val("SELECT id FROM prpt_blocks WHERE property_id = ? AND code = 'A'", [$KH]);
$say(count($U) . ' units in 3 properties');

/* amenities, parking, notices, charges, meters */
foreach ([['Swimming pool', 'fa-person-swimming', 'all', 'Daily 6am – 9pm', null], ['Gym', 'fa-dumbbell', 'all', 'Daily 5am – 10pm', null], ['Rooftop terrace', 'fa-champagne-glasses', 'booking_required', 'Book via the portal', 2000], ['Borehole & backup water', 'fa-droplet', 'all', '24/7', null], ['Children\'s play area', 'fa-children', 'all', '8am – 7pm', null]] as [$n, $i, $a, $s, $fee])
    insert('prpt_amenities', ['property_id' => $KH, 'name' => $n, 'icon' => $i, 'access' => $a, 'schedule' => $s, 'fee' => $fee]);
insert('prpt_amenities', ['property_id' => $WBP, 'name' => 'Backup generator', 'icon' => 'fa-bolt', 'access' => 'all', 'schedule' => '24/7']);
insert('prpt_amenities', ['property_id' => $WBP, 'name' => 'Boardroom', 'icon' => 'fa-people-roof', 'access' => 'booking_required', 'schedule' => 'Weekdays 8am – 6pm', 'fee' => 3500]);
foreach (['Pool closed for cleaning every Monday 6–10am' => 'schedule', 'Garbage collection: Tuesdays & Fridays before 8am' => 'schedule', 'No loud music after 10pm; quiet hours 10pm – 6am' => 'rule', 'Visitors must be booked in at the gate' => 'rule'] as $t => $k)
    insert('prpt_notices', ['property_id' => $KH, 'kind' => $k, 'title' => $t, 'body' => $t . '.', 'audience' => 'all', 'is_pinned' => (int)($k === 'rule')]);
insert('prpt_notices', ['property_id' => $KH, 'kind' => 'notice', 'title' => 'Water interruption — 20 Nov, 9am to 3pm', 'body' => 'Nairobi Water will be working on the main line. Please store water. The borehole will supply common areas only.', 'audience' => 'all', 'is_pinned' => 1, 'ends_at' => '2026-11-21 00:00:00']);
foreach ($khU as $no => $uid) { $slot = 'P-' . str_replace('-', '', $no); insert('prpt_parking_slots', ['property_id' => $KH, 'code' => $slot, 'slot_type' => str_starts_with($no, 'A') ? 'covered' : 'basement', 'unit_id' => $uid, 'status' => 'allocated']); }
for ($i = 1; $i <= 6; $i++) insert('prpt_parking_slots', ['property_id' => $KH, 'code' => 'V-' . $i, 'slot_type' => 'visitor', 'status' => 'available']);
insert('prpt_property_charges', ['property_id' => $KH, 'bill_type_id' => $bt('GARBAGE'), 'amount' => 500, 'applies_to' => 'all', 'is_mandatory' => 1]);
insert('prpt_property_charges', ['property_id' => $KH, 'bill_type_id' => $bt('WATER'), 'unit_rate' => 150, 'applies_to' => 'all', 'is_mandatory' => 1]);
insert('prpt_property_charges', ['property_id' => $WBP, 'bill_type_id' => $bt('ELEC'), 'unit_rate' => 28, 'applies_to' => 'all', 'is_mandatory' => 1]);
$M = [];
foreach ($khU as $no => $uid) if (!in_array($no, ['A-301', 'A-302'], true)) $M[$no] = insert('prpt_meters', ['property_id' => $KH, 'unit_id' => $uid, 'bill_type_id' => $bt('WATER'), 'serial_no' => 'WTR-' . str_replace('-', '', $no) . '-' . mt_rand(100, 999), 'initial_reading' => mt_rand(800, 1500), 'install_date' => '2019-06-01']);
foreach ($wbU as $no => $uid) $M[$no] = insert('prpt_meters', ['property_id' => $WBP, 'unit_id' => $uid, 'bill_type_id' => $bt('ELEC'), 'serial_no' => 'KPLC-' . $no . '-' . mt_rand(1000, 9999), 'initial_reading' => mt_rand(20000, 40000), 'install_date' => '2016-03-01']);
insert('prpt_meters', ['property_id' => $KH, 'unit_id' => null, 'bill_type_id' => $bt('WATER'), 'serial_no' => 'WTR-BULK-01', 'initial_reading' => 56000]);
$say(count($M) . ' meters, amenities, parking, notices');

/* ---------------- owners ---------------- */
$peter = $person('Peter', 'Otieno', ['is_owner' => 1, 'gender' => 'male', 'occupation' => 'Investor', 'nok' => 'Anne Otieno']);
$mary = $person('Mary', 'Wambui', ['is_owner' => 1, 'gender' => 'female', 'occupation' => 'Doctor']);
$james = $person('James', 'Mwangi', ['is_owner' => 1, 'gender' => 'male', 'occupation' => 'Engineer']);
$sarah = $person('Sarah', 'Kimani', ['is_owner' => 1, 'gender' => 'female', 'occupation' => 'Lawyer']);
$david = $person('David', 'Kimani', ['is_owner' => 1, 'gender' => 'male', 'occupation' => 'Banker']);
$ahmed = $person('Ahmed', 'Hassan', ['is_owner' => 1, 'gender' => 'male', 'occupation' => 'Businessman']);
$ruth = $person('Ruth', 'Chebet', ['is_owner' => 1, 'gender' => 'female', 'occupation' => 'Architect']);
$ncl = $company('Nairobi Capital Ltd', ['is_owner' => 1]);
$vdl = $company('Vikao Developments Ltd', ['is_owner' => 1]);
foreach ([$peter => ['KCB', 'Kilimani', '1100223344'], $ahmed => ['Equity', 'Eastleigh', '0450199887766'], $ncl => ['Stanbic', 'Westlands', '0100045678912'], $mary => ['Co-op', 'Upper Hill', '01109988776600']] as $pid => [$bank, $br, $acc])
    insert('prpt_party_bank_accounts', ['party_id' => $pid, 'method' => 'bank', 'bank_name' => $bank . ' Bank', 'branch' => $br, 'account_name' => val("SELECT display_name FROM prpt_parties WHERE id = ?", [$pid]), 'account_number' => $acc, 'is_default' => 1]);
$own($peter, 'block', $KH, $blockA, null, 100, '2019-08-01', 'developer');
foreach (['B-G01', 'B-G02', 'B-G03', 'B-G04'] as $no) $own($mary, 'unit', $KH, null, $U[$no], 100, '2020-02-15');
foreach (['B-101', 'B-102'] as $no) $own($james, 'unit', $KH, null, $U[$no], 100, '2020-05-01');
$own($sarah, 'unit', $KH, null, $U['B-103'], 60, '2021-03-10'); $own($david, 'unit', $KH, null, $U['B-103'], 40, '2021-03-10');
foreach (['B-104', 'B-203', 'B-204', 'B-301', 'B-302', 'B-303', 'B-304'] as $no) $own($ahmed, 'unit', $KH, null, $U[$no], 100, '2020-09-01');
foreach (['B-201', 'B-202'] as $no) $own($ruth, 'unit', $KH, null, $U[$no], 100, '2021-01-20');
$own($ncl, 'property', $WBP, null, null, 100, '2016-01-01', 'developer');
$own($vdl, 'property', $KG, null, null, 100, '2025-06-01', 'developer');
insert('prpt_mgmt_agreements', ['property_id' => $KH, 'party_id' => null, 'fee_type' => 'percentage', 'fee_value' => 10, 'fee_base' => 'collected', 'fee_bill_types' => 'RENT', 'vat_on_fee' => 1, 'sales_commission_type' => 'percentage', 'sales_commission_value' => 3, 'letting_fee_type' => 'percentage_of_rent', 'letting_fee_value' => 50, 'remittance_day' => 10, 'start_date' => '2024-01-01', 'is_active' => 1]);
insert('prpt_mgmt_agreements', ['property_id' => $KH, 'party_id' => $ahmed, 'fee_type' => 'fixed_per_occupied_unit', 'fee_value' => 3500, 'fee_base' => 'collected', 'fee_bill_types' => 'RENT', 'vat_on_fee' => 1, 'letting_fee_type' => 'none', 'remittance_day' => 10, 'start_date' => '2024-01-01', 'is_active' => 1, 'notes' => 'Negotiated flat fee per let unit']);
insert('prpt_mgmt_agreements', ['property_id' => $WBP, 'party_id' => $ncl, 'fee_type' => 'percentage', 'fee_value' => 8, 'fee_base' => 'collected', 'fee_bill_types' => 'RENT', 'vat_on_fee' => 1, 'letting_fee_type' => 'fixed', 'letting_fee_value' => 50000, 'remittance_day' => 10, 'start_date' => '2023-01-01', 'is_active' => 1]);
insert('prpt_mgmt_agreements', ['property_id' => $KG, 'party_id' => $vdl, 'fee_type' => 'percentage', 'fee_value' => 0, 'fee_base' => 'collected', 'fee_bill_types' => 'RENT', 'vat_on_fee' => 0, 'sales_commission_type' => 'percentage', 'sales_commission_value' => 5, 'letting_fee_type' => 'none', 'remittance_day' => 10, 'start_date' => '2025-06-01', 'is_active' => 1]);
$say('9 owners incl. a block owner, co-owners and two companies; 4 management agreements');

/* ---------------- tenants & accounts ---------------- */
// unit => [first, last, start, profile]   profile: good | late | partial | bad | over
$plan = [
    'A-G01' => ['Jane', 'Wanjiku', '2025-03-01', 'good'], 'A-G02' => ['Tom', 'Ochieng', '2025-08-01', 'bad'], 'A-G03' => ['Grace', 'Akinyi', '2026-02-01', 'good'], 'A-G04' => ['Samuel', 'Kiptoo', '2025-11-01', 'late'],
    'A-101' => ['Esther', 'Nyambura', '2024-07-01', 'good'], 'A-102' => ['Mercy', 'Chepkoech', '2026-01-15', 'partial'], 'A-103' => ['Kevin', 'Mutua', '2025-05-01', 'over'],
    'A-201' => ['Faith', 'Wairimu', '2025-10-01', 'good'], 'A-202' => ['John', 'Kamau', '2026-04-01', 'late'], 'A-204' => ['Lilian', 'Atieno', '2026-09-12', 'good'],
    'A-303' => ['Alice', 'Moraa', '2025-01-01', 'good'], 'A-304' => ['Collins', 'Kibet', '2026-06-01', 'partial'],
    'B-G01' => ['Diana', 'Mumbi', '2025-09-01', 'good'], 'B-G02' => ['Victor', 'Onyango', '2026-03-01', 'late'], 'B-G03' => ['Naomi', 'Jeptoo', '2024-12-01', 'good'],
    'B-101' => ['Felix', 'Mutiso', '2025-06-01', 'good'], 'B-102' => ['Irene', 'Wanjiru', '2026-05-01', 'bad'], 'B-103' => ['Martin', 'Kariuki', '2025-02-01', 'good'],
    'B-203' => ['Joyce', 'Adhiambo', '2025-04-01', 'good'], 'B-204' => ['George', 'Njoroge', '2026-07-01', 'over'], 'B-301' => ['Caroline', 'Muthoni', '2025-12-01', 'good'], 'B-302' => ['Patrick', 'Waweru', '2026-08-01', 'late'],
];
$profiles = []; $leaseOf = []; $partyOf = [];
$leaseEnd = function (string $start) { $y = 1; do { $end = date('Y-m-d', strtotime($start . " +$y years -1 day")); $y++; } while ($end < date('Y-m-d', strtotime('+25 days'))); return $end; }; // renewed yearly
$openAccount = function (string $no, int $tenant, string $start, string $occ = 'tenant_managed', ?int $ownerParty = null) use (&$U, &$leaseOf, $leaseEnd) {
    $uid = $U[$no]; $owner = $ownerParty ?? unit_primary_owner_id($uid);
    $charges = lease_default_charges($uid, $occ, in_array($occ, ['owner_occupied', 'owner_vacant'], true) ? null : $tenant, $owner);
    $parties = in_array($occ, ['owner_occupied', 'owner_vacant'], true) ? [['party_id' => $owner, 'role' => 'owner', 'is_primary' => 1]] : [['party_id' => $tenant, 'role' => $occ === 'anonymous' ? 'occupant' : 'tenant', 'is_primary' => 1]];
    $u = one("SELECT * FROM prpt_units WHERE id = ?", [$uid]);
    $l = lease_create(['unit_id' => $uid, 'occupancy_type' => $occ, 'start_date' => $start, 'end_date' => in_array($occ, ['tenant_managed', 'company_let'], true) ? $leaseEnd($start) : null,
        'rent_amount' => $u['rent_amount'], 'billing_day' => 1, 'due_day' => (int)val("SELECT due_day FROM prpt_properties WHERE id = ?", [$u['property_id']]), 'deposit_amount' => in_array($occ, ['tenant_managed', 'company_let'], true) ? (float)$u['rent_amount'] * ($u['usage_class'] === 'commercial' ? 3 : 1) : 0,
        'escalation_pct' => $u['usage_class'] === 'commercial' ? 7.5 : 5, 'escalation_every_months' => 12, 'notice_period_days' => $u['usage_class'] === 'commercial' ? 90 : 30], $parties, $charges);
    $leaseOf[$no] = (int)$l['id'];
    return $l;
};
foreach ($plan as $no => [$f, $l, $start, $prof]) {
    $tid = $person($f, $l, ['is_tenant' => 1, 'occupation' => ['Accountant', 'Teacher', 'Nurse', 'Software developer', 'Sales manager', 'Pharmacist', 'Civil servant', 'Marketer'][mt_rand(0, 7)], 'employer' => ['Safaricom', 'KCB Group', 'Aga Khan Hospital', 'Andela', 'Ministry of Health', 'EABL', 'Deloitte', 'UNEP'][mt_rand(0, 7)], 'nok' => 'Next of kin']);
    $openAccount($no, $tid, $start); $profiles[$no] = $prof; $partyOf[$no] = $tid;
}
// owner arrangements
$ruthAcc = $openAccount('B-201', 0, '2024-01-01', 'owner_occupied', $ruth); $openAccount('B-202', 0, '2024-01-01', 'owner_vacant', $ruth);
$profiles['B-201'] = 'good'; $profiles['B-202'] = 'late'; $partyOf['B-201'] = $partyOf['B-202'] = $ruth;
$amosT = $person('Amos', 'Kiprotich', ['is_tenant' => 1]);
$openAccount('B-104', $amosT, '2025-07-01', 'tenant_owner_managed'); $profiles['B-104'] = 'good'; $partyOf['B-104'] = $amosT; // Ahmed collects rent himself; we bill SC (owner) + water/garbage (tenant)
$anon = party_create(['first_name' => 'Occupant', 'last_name' => 'B-G04', 'is_tenant' => 1, 'notes' => 'Anonymous occupant — tenant details not known']);
$openAccount('B-G04', $anon, '2026-03-01', 'anonymous'); $profiles['B-G04'] = 'partial'; $partyOf['B-G04'] = $anon;
foreach (['101' => ['Acme Logistics Ltd', '2024-04-01', 'good'], '102' => ['Savanna Tech Ltd', '2025-01-01', 'good'], '103' => ['BlueRiver Insurance Brokers', '2025-07-01', 'late'], '201' => ['Tamu Foods Ltd', '2023-10-01', 'good'],
          '202' => ['Jenga Architects', '2026-02-01', 'over'], '301' => ['Mwangaza Consulting', '2026-05-01', 'partial']] as $no => [$name, $start, $prof]) {
    $cid = $company($name, ['is_tenant' => 1]); $openAccount((string)$no, $cid, $start, 'company_let'); $profiles[(string)$no] = $prof; $partyOf[(string)$no] = $cid;
}
$say(count($leaseOf) . ' billing accounts (tenant-managed, owner-managed, owner-occupied, owner-vacant, anonymous, company lets)');

/* deposits: paid & held for everyone except the newest two */
foreach (all("SELECT d.*, l.unit_id, l.start_date FROM bln_deposits d JOIN bln_leases l ON l.id = d.lease_id") as $d) {
    $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [$d['invoice_id']]);
    $paidAt = date('Y-m-d 10:15:00', strtotime($d['start_date'] . ' -3 days'));
    q("UPDATE bln_invoices SET issue_date = ?, due_date = ?, created_at = ? WHERE id = ?", [date('Y-m-d', strtotime($d['start_date'] . ' -7 days')), $d['start_date'], $paidAt, $inv['id']]);
    q("UPDATE bln_invoice_events SET created_at = ? WHERE invoice_id = ?", [date('Y-m-d 09:00:00', strtotime($d['start_date'] . ' -7 days')), $inv['id']]);
    $pay = payment_record(['account_id' => (int)val("SELECT id FROM fin_accounts WHERE code = 'VK-COOP'"), 'method' => 'bank_transfer', 'reference' => 'FT' . strtoupper(random_code(10, '0123456789ABCDEF')), 'amount' => (float)$inv['total'],
        'party_id' => (int)$d['party_id'], 'lease_id' => (int)$d['lease_id'], 'unit_id' => (int)$d['unit_id'], 'paid_at' => $paidAt, 'notes' => 'Deposit']);
    payment_allocate((int)$pay['id'], array_map(fn($l) => ['line_id' => (int)$l['id'], 'amount' => (float)$l['balance']], invoice_lines_with_balance((int)$inv['id'])), null, 'manual');
    q("UPDATE fin_allocations SET created_at = ? WHERE payment_id = ?", [$paidAt, $pay['id']]); q("UPDATE bln_invoice_events SET created_at = ? WHERE invoice_id = ? AND event = 'payment'", [$paidAt, $inv['id']]);
    update('bln_deposits', ['amount_held' => (float)$inv['total'], 'status' => 'held', 'held_by' => 'agency'], 'id = :id', ['id' => $d['id']]);
}
$say('deposits invoiced, paid and held');

/* ---------------- 6 months of readings, invoices & payments ---------------- */
$mpesaAcc = (int)val("SELECT id FROM fin_accounts WHERE code = 'VK-MPESA'"); $coopAcc = (int)val("SELECT id FROM fin_accounts WHERE code = 'VK-COOP'"); $cashAcc = (int)val("SELECT id FROM fin_accounts WHERE code = 'VK-CASH'");
$periods = ['2026-06', '2026-07', '2026-08', '2026-09', '2026-10', '2026-11'];
$unitNoOf = array_flip($U);
$payInvoice = function (array $inv, float $amount, string $at, string $how) use ($mpesaAcc, $coopAcc, $cashAcc, $unitNoOf) {
    if ($amount <= 0) return null;
    $phone = (string)val("SELECT phone FROM prpt_parties WHERE id = ?", [$inv['party_id']]); $name = strtoupper((string)val("SELECT display_name FROM prpt_parties WHERE id = ?", [$inv['party_id']]));
    $ref = $unitNoOf[$inv['unit_id']] ?? $inv['invoice_no'];
    if ($how === 'cash') { $p = payment_record(['account_id' => $inv['party_id'] && val("SELECT is_owner FROM prpt_parties WHERE id = ?", [$inv['party_id']]) ? $coopAcc : $cashAcc, 'method' => val("SELECT is_owner FROM prpt_parties WHERE id = ?", [$inv['party_id']]) ? 'bank_transfer' : 'cash', 'reference' => 'CSH' . random_code(6, '0123456789'), 'amount' => $amount, 'party_id' => $inv['party_id'], 'lease_id' => $inv['lease_id'], 'unit_id' => $inv['unit_id'], 'paid_at' => $at]); $p = payment_auto_allocate((int)$p['id']); }
    else { $r = ingest_gateway_txn($how === 'bank' ? 'coop' : 'mpesa', ['transaction_id' => ($how === 'bank' ? 'FT' : 'T') . strtoupper(random_code(9, 'ABCDEFGHJKLMNPQRSTUVWXYZ0123456789')), 'transaction_type' => $how === 'bank' ? 'Bank credit' : 'Pay Bill', 'amount' => $amount,
        'payer_name' => $name, 'payer_phone' => normalize_phone($phone), 'bill_ref' => $ref, 'transacted_at' => $at, 'account_id' => $how === 'bank' ? $coopAcc : $mpesaAcc]); $p = $r['payment']; }
    q("UPDATE fin_allocations SET created_at = ? WHERE payment_id = ?", [$at, $p['id']]); q("UPDATE fin_payments SET created_at = ? WHERE id = ?", [$at, $p['id']]);
    q("UPDATE bln_invoice_events SET created_at = ? WHERE event = 'payment' AND description LIKE ?", [$at, '%' . $p['receipt_no'] . '%']);
    q("UPDATE fin_credits SET created_at = ? WHERE payment_id = ?", [$at, $p['id']]);
    return $p;
};
$ownerProfile = [$peter => 'good', $ahmed => 'late', $mary => 'good', $james => 'partial', $sarah => 'good', $ruth => 'good', $ncl => 'good'];
$lastRead = []; foreach ($M as $no => $mid) $lastRead[$mid] = (float)val("SELECT initial_reading FROM prpt_meters WHERE id = ?", [$mid]);
/* a real demo meter photo (drawn with GD) so field readings show an image */
$demoPhoto = (function (): ?string {
    if (!function_exists('imagecreatetruecolor')) return null;
    $rel = 'readings/2026/11/' . bin2hex(random_bytes(8)) . '.jpg'; $abs = upload_abs($rel);
    if (!is_dir(dirname($abs))) @mkdir(dirname($abs), 0775, true);
    $im = imagecreatetruecolor(640, 480);
    $bg = imagecolorallocate($im, 58, 64, 72); imagefill($im, 0, 0, $bg);
    for ($i = 0; $i < 480; $i += 6) imageline($im, 0, $i, 640, $i + 30, imagecolorallocate($im, 62 + $i % 9, 68, 76));
    imagefilledellipse($im, 320, 240, 400, 400, imagecolorallocate($im, 30, 58, 110));
    imagefilledellipse($im, 320, 240, 350, 350, imagecolorallocate($im, 235, 238, 240));
    imagefilledrectangle($im, 200, 200, 440, 260, imagecolorallocate($im, 20, 20, 20));
    $digits = '01416'; $white = imagecolorallocate($im, 255, 255, 255); $red = imagecolorallocate($im, 220, 40, 40);
    for ($d = 0; $d < 5; $d++) { imagerectangle($im, 208 + $d * 46, 206, 248 + $d * 46, 254, imagecolorallocate($im, 90, 90, 90)); imagestring($im, 5, 224 + $d * 46, 222, $digits[$d], $d === 4 ? $red : $white); }
    imagestring($im, 3, 292, 280, 'm3', imagecolorallocate($im, 40, 40, 40));
    imagestring($im, 2, 262, 150, 'WATER METER', imagecolorallocate($im, 40, 40, 40));
    imagestring($im, 2, 8, 460, 'Vikao demo photo', imagecolorallocate($im, 200, 200, 200));
    imagejpeg($im, $abs, 82); imagedestroy($im);
    return $rel;
})();

foreach ($periods as $pi => $period) {
    $readDate = date('Y-m-25', strtotime($period . '-01 -1 month'));
    foreach ($M as $no => $mid) {
        if (!isset($leaseOf[$no])) continue;
        $l = one("SELECT start_date FROM bln_leases WHERE id = ?", [$leaseOf[$no]]); if ($l['start_date'] > $readDate) continue;
        $isElec = !preg_match('/^[AB]-/', (string)$no);
        $use = $isElec ? mt_rand(900, 1600) : mt_rand(7, 13);
        if ($no === 'A-102' && $period === '2026-11') $use = 46; // anomaly → held for review
        $lastRead[$mid] += $use;
        try { reading_save($mid, $lastRead[$mid], ['date' => $readDate, 'source' => $pi % 2 ? 'field' : 'bulk', 'photo_path' => $pi % 2 ? $demoPhoto : null]); } catch (Throwable $e) { /* ignore */ }
    }
    q("UPDATE prpt_meter_readings SET created_at = ?, read_by = ? WHERE reading_date = ?", [$readDate . ' 11:30:00', $S['joseph'], $readDate]);
    $r = run_billing($period, null, [], false, 'scheduled');
    [$ps] = period_bounds($period);
    foreach (all("SELECT i.id, p.due_day FROM bln_invoices i JOIN prpt_properties p ON p.id = i.property_id WHERE i.run_id = ?", [$r['run_id']]) as $x)
        q("UPDATE bln_invoices SET issue_date = ?, due_date = ?, created_at = ?, sent_at = ? WHERE id = ?", [$ps, sprintf('%s-%02d', $period, (int)$x['due_day']), $ps . ' 06:00:00', $ps . ' 06:05:00', $x['id']]);
    q("UPDATE bln_invoice_events SET created_at = ? WHERE invoice_id IN (SELECT id FROM bln_invoices WHERE run_id = ?)", [$ps . ' 06:00:00', $r['run_id']]);
    q("UPDATE bln_billing_runs SET created_at = ? WHERE id = ?", [$ps . ' 06:00:00', $r['run_id']]);
    foreach (all("SELECT * FROM bln_invoices WHERE run_id = ? ORDER BY id", [$r['run_id']]) as $inv) {
        invoice_recalc((int)$inv['id']); $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [$inv['id']]);
        if ((float)$inv['balance'] <= 0) continue;
        $no = $unitNoOf[$inv['unit_id']] ?? null; $prof = $profiles[$no] ?? 'good';
        $how = ['mpesa', 'mpesa', 'mpesa', 'bank', 'mpesa', 'cash'][mt_rand(0, 5)]; if (is_numeric((string)$no)) $how = 'bank';
        if ($inv['lease_id'] && (int)$inv['party_id'] !== (int)lease_primary_payer((int)$inv['lease_id'])) { $prof = $ownerProfile[(int)$inv['party_id']] ?? 'good'; $how = 'cash'; } // owner-billed (service charge)
        $isNow = $period === date('Y-m');
        $day = fn(int $a, int $b) => date('Y-m-d H:i:s', strtotime(sprintf('%s-%02d %02d:%02d:00', $period, mt_rand($a, $b), mt_rand(7, 21), mt_rand(0, 59))));
        switch ($prof) {
            case 'good': $at = $day(1, $isNow ? min(5, (int)date('j')) : 5); $payInvoice($inv, (float)$inv['balance'], $at, $how); break;
            case 'late': if ($isNow) break; $payInvoice($inv, (float)$inv['balance'], $day(12, 24), $how); break;
            case 'partial': $payInvoice($inv, round((float)$inv['balance'] * 0.6 / 100) * 100, $day(3, $isNow ? min(10, (int)date('j')) : 15), $how); break;
            case 'bad': if ($pi < 3) $payInvoice($inv, (float)$inv['balance'], $day(8, 20), 'mpesa'); elseif ($pi === 3) $payInvoice($inv, 20000, $day(10, 20), 'mpesa'); break;
            case 'over': $at = $day(1, $isNow ? min(4, (int)date('j')) : 4); $payInvoice($inv, ceil(((float)$inv['balance'] + 5000) / 1000) * 1000, $at, $how); break;
        }
    }
}
$say(count($periods) . ' months of meter readings, billing runs and payments');
// one unmatched M-Pesa payment (wrong account number) waiting to be consumed
ingest_gateway_txn('mpesa', ['transaction_id' => 'TKB' . strtoupper(random_code(7)), 'transaction_type' => 'Pay Bill', 'amount' => 23500, 'payer_name' => 'TOM OCHIENG', 'payer_phone' => '+254711000999', 'bill_ref' => 'TOM RENT', 'transacted_at' => date('Y-m-d 08:42:00', strtotime('-1 day')), 'account_id' => $mpesaAcc]);
ingest_gateway_txn('coop', ['transaction_id' => 'FT' . strtoupper(random_code(10, '0123456789')), 'transaction_type' => 'Bank credit', 'amount' => 54000, 'payer_name' => 'J MUTUA', 'bill_ref' => null, 'transacted_at' => date('Y-m-d 14:10:00', strtotime('-2 days')), 'account_id' => $coopAcc]);
refresh_overdue();
foreach (col("SELECT id FROM bln_invoices WHERE status IN ('issued','partially_paid','overdue')") as $iid) invoice_recalc((int)$iid);
// a manual one-off invoice and a credit note for the timeline demo
$inv = invoice_create(['party_id' => $partyOf['A-103'], 'property_id' => $KH, 'unit_id' => $U['A-103'], 'lease_id' => $leaseOf['A-103'], 'issue_date' => date('Y-m-d', strtotime('-9 days')), 'due_date' => date('Y-m-d', strtotime('+5 days')), 'source' => 'manual', 'notes' => 'Replacement of broken kitchen window (tenant damage)'],
    [['bill_type_id' => $bt('OTHER'), 'description' => 'Kitchen window glass replacement', 'quantity' => 1, 'unit_price' => 8500]]);
invoice_credit_note((int)$inv['id'], 1500, 'Goodwill discount — glass sourced cheaper');
$say('unmatched payments, manual invoice with credit note');

/* ---------------- expenses ---------------- */
$cat = fn(string $c) => (int)val("SELECT id FROM fin_expense_categories WHERE code = ?", [$c]);
$vend = [];
foreach ([['Maji Fix Plumbers', 'Plumbing', '0722333444'], ['Sparky Electricals', 'Electrical', '0733222111'], ['SafeGuard Security Ltd', 'Security', '0700555666'], ['CleanPro Services', 'Cleaning', '0711777888'], ['Liftech Kenya', 'Lift maintenance', '0720999000']] as [$n, $s, $ph])
    $vend[$s] = insert('fin_vendors', ['name' => $n, 'service' => $s, 'phone' => $ph, 'email' => strtolower(str_replace(' ', '', $n)) . '@example.com', 'rating' => mt_rand(35, 50) / 10, 'is_active' => 1]);
foreach ($periods as $period) {
    insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat('SECURITY'), 'vendor_id' => $vend['Security'], 'expense_date' => $period . '-28', 'description' => 'Guarding services — ' . month_label($period), 'amount' => 85000, 'property_id' => $KH, 'chargeable_to' => 'property_fund', 'status' => 'paid']);
    insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat('CLEANING'), 'vendor_id' => $vend['Cleaning'], 'expense_date' => $period . '-27', 'description' => 'Common area cleaning — ' . month_label($period), 'amount' => 38000, 'property_id' => $KH, 'chargeable_to' => 'property_fund', 'status' => 'paid']);
    insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat('REPAIRS'), 'vendor_id' => $vend['Lift maintenance'], 'expense_date' => $period . '-15', 'description' => 'Lift service contract — ' . month_label($period), 'amount' => 24000, 'property_id' => $WBP, 'chargeable_to' => 'owner', 'party_id' => $ncl, 'status' => 'paid']);
}
foreach ([['B-203', 'Bathroom tap & shower mixer replacement', 6800, $ahmed], ['A-G02', 'Repaint after water damage', 14500, $peter], ['B-101', 'Water heater element', 4200, $james]] as [$no, $d, $amt, $ow])
    insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat('REPAIRS'), 'vendor_id' => $vend['Plumbing'], 'expense_date' => date('Y-m-d', strtotime('-' . mt_rand(10, 40) . ' days')), 'description' => $d, 'amount' => $amt, 'property_id' => $KH, 'unit_id' => $U[$no], 'party_id' => $ow, 'chargeable_to' => 'owner', 'status' => 'approved']);
insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat('MARKETING'), 'expense_date' => date('Y-m-d', strtotime('-6 days')), 'description' => 'Professional photos for vacant units', 'amount' => 15000, 'property_id' => $KH, 'chargeable_to' => 'agency', 'status' => 'draft']);
$say('expenses & vendors');

/* ---------------- remittances (Sep & Oct paid, Nov to prepare) ---------------- */
foreach ([['2026-09-01', '2026-09-30'], ['2026-10-01', '2026-10-31']] as [$a, $b]) {
    foreach ([[$peter, $KH], [$ahmed, $KH], [$james, $KH], [$mary, $KH], [$sarah, $KH], [$ncl, $WBP]] as [$o, $p]) {
        try { $r = remittance_create($o, $p, $a, $b); } catch (Throwable $e) { continue; }
        update('fin_remittances', ['status' => 'paid', 'paid_at' => date('Y-m-10 15:00:00', strtotime($b . ' +1 day')), 'payment_ref' => 'RTGS' . random_code(8, '0123456789'), 'paid_from_account_id' => $coopAcc, 'approved_by' => $S['daniel'], 'created_at' => date('Y-m-08 10:00:00', strtotime($b . ' +1 day'))], 'id = :id', ['id' => $r['id']]);
    }
}
$say('owner remittances for Sep & Oct');

/* ---------------- CRM ---------------- */
$catId = fn(string $n) => (int)val("SELECT id FROM crm_categories WHERE name LIKE ?", [$n . '%']);
$tk = [];
foreach ([['A-G01', 'Plumbing', 'Kitchen sink is leaking under the cabinet', 'Water drips constantly when the tap is on. Started yesterday.', 'high', 'resolved', 'portal', -12],
          ['A-G02', 'Billing', 'Please explain my water bill for October', 'The water charge looks higher than usual.', 'medium', 'awaiting_customer', 'portal', -5],
          ['B-103', 'Electrical', 'Sockets in the master bedroom not working', 'Both sockets dead since the storm.', 'high', 'in_progress', 'portal', -2],
          ['A-101', 'Noise', 'Loud music from the unit above after midnight', 'Third weekend in a row.', 'medium', 'assigned', 'portal', -1],
          ['101', 'Lift', 'Lift 2 stuck between floors this morning', 'Staff were trapped for 15 minutes.', 'urgent', 'open', 'phone', 0],
          ['B-G01', 'Cleaning', 'Garbage not collected on Friday', 'Bins are overflowing at the B-block chute.', 'low', 'closed', 'whatsapp', -20],
          ['A-304', 'Move-out', 'Notice to vacate end of December', 'I have been transferred to Mombasa.', 'medium', 'open', 'portal', -1],
          ['B-301', 'Security', 'Unknown person tried to access the parking', 'Guard let them in without checking.', 'urgent', 'resolved', 'portal', -30]] as [$no, $c, $subj, $desc, $prio, $st, $src, $ago]) {
    $party = $partyOf[$no] ?? null;
    $t = ticket_create(['category_id' => $catId($c), 'unit_id' => $U[$no], 'party_id' => $party, 'subject' => $subj, 'description' => $desc, 'priority' => $prio, 'source' => $src, 'access_permission' => 'with_notice', 'preferred_time' => 'Weekdays after 5pm'], $src === 'portal' ? 'portal' : 'staff', $src === 'portal' ? $party : $S['faith']);
    $created = date('Y-m-d H:i:s', strtotime("$ago days 09:30"));
    q("UPDATE crm_tickets SET created_at = ?, sla_due_at = DATE_ADD(?, INTERVAL TIMESTAMPDIFF(SECOND, created_at, sla_due_at) SECOND), assigned_to = COALESCE(assigned_to, ?) WHERE id = ?", [$created, $created, $S['faith'], $t['id']]);
    if ($st !== 'open') {
        ticket_add_update((int)$t['id'], 'Thanks for reporting — a technician has been assigned and will call you before visiting.', false, 'assigned', 'staff', $S['faith']);
        if (in_array($st, ['in_progress', 'resolved', 'closed', 'awaiting_customer'], true)) ticket_add_update((int)$t['id'], 'Vendor confirmed for tomorrow 10am.', true, 'in_progress', 'staff', $S['faith']);
        if ($st === 'awaiting_customer') ticket_add_update((int)$t['id'], 'Your October reading was 13 m³ vs 9 m³ average. Could you check for running toilets? Reply here and we will re-read the meter.', false, 'awaiting_customer', 'staff', $S['faith']);
        if (in_array($st, ['resolved', 'closed'], true)) { ticket_add_update((int)$t['id'], 'Fixed and tested. Please confirm all is well.', false, 'resolved', 'staff', $S['faith']); if ($party) ticket_add_update((int)$t['id'], 'All good now, thank you!', false, null, 'portal', $party); }
        if ($st === 'closed') ticket_add_update((int)$t['id'], 'Closing — thank you.', false, 'closed', 'staff', $S['faith']);
        foreach (col("SELECT id FROM crm_ticket_updates WHERE ticket_id = ? ORDER BY id", [$t['id']]) as $k => $uid) q("UPDATE crm_ticket_updates SET created_at = DATE_ADD(?, INTERVAL ? HOUR) WHERE id = ?", [$created, ($k + 1) * 3, $uid]);
        q("UPDATE crm_tickets SET first_response_at = DATE_ADD(created_at, INTERVAL 2 HOUR), resolved_at = IF(status IN ('resolved','closed'), DATE_ADD(created_at, INTERVAL 20 HOUR), NULL) WHERE id = ?", [$t['id']]);
    }
    if ($st === 'resolved' || $st === 'closed') q("UPDATE crm_tickets SET rating = ?, rating_comment = ? WHERE id = ?", [mt_rand(4, 5), 'Quick response, thank you', $t['id']]);
    $tk[$no] = $t['id'];
}
q("UPDATE crm_tickets SET vendor_id = ?, cost_actual = 6500, cost_bearer = 'owner' WHERE id = ?", [$vend['Plumbing'], $tk['A-G01']]);
insert('fin_expenses', ['expense_no' => next_no('expense'), 'category_id' => $cat('REPAIRS'), 'vendor_id' => $vend['Plumbing'], 'expense_date' => date('Y-m-d', strtotime('-11 days')), 'description' => 'Sink waste pipe & trap replacement (A-G01)', 'amount' => 6500, 'property_id' => $KH, 'unit_id' => $U['A-G01'], 'party_id' => $peter, 'ticket_id' => $tk['A-G01'], 'chargeable_to' => 'owner', 'status' => 'paid']);
insert('crm_maintenance_schedules', ['property_id' => $KH, 'title' => 'Borehole pump service', 'category_id' => $catId('Plumbing'), 'vendor_id' => $vend['Plumbing'], 'every_days' => 90, 'next_due_date' => date('Y-m-d', strtotime('+12 days')), 'estimated_cost' => 18000, 'is_active' => 1]);
insert('crm_maintenance_schedules', ['property_id' => $WBP, 'title' => 'Lift statutory inspection', 'category_id' => $catId('Lift'), 'vendor_id' => $vend['Lift maintenance'], 'every_days' => 180, 'next_due_date' => date('Y-m-d', strtotime('+40 days')), 'estimated_cost' => 35000, 'is_active' => 1]);
insert('crm_maintenance_schedules', ['property_id' => $KH, 'title' => 'Fumigation of common areas', 'category_id' => $catId('Cleaning'), 'every_days' => 120, 'next_due_date' => date('Y-m-d', strtotime('-1 day')), 'estimated_cost' => 22000, 'is_active' => 1]);
insert('crm_inspections', ['unit_id' => $U['A-204'], 'lease_id' => $leaseOf['A-204'], 'inspection_type' => 'move_in', 'inspected_on' => '2026-09-11', 'inspector_id' => $S['grace'], 'overall' => 'good',
    'checklist' => json_encode([['area' => 'Living room', 'condition' => 'good', 'note' => ''], ['area' => 'Kitchen', 'condition' => 'excellent', 'note' => 'New cooker'], ['area' => 'Bathroom', 'condition' => 'fair', 'note' => 'Grout stained'], ['area' => 'Walls & paint', 'condition' => 'good', 'note' => '']]), 'notes' => 'Keys (3) and gate cards (2) handed over.']);
$say(count($tk) . ' tickets with conversation threads, maintenance schedules, an inspection');

/* ---------------- sales ---------------- */
$stage = fn(string $n) => (int)val("SELECT id FROM sls_stages WHERE name = ?", [$n]);
$leads = [];
foreach ([['Wanjiru Gathoni', 'buy', 'referral', 'Won', 'TH-01', 19250000], ['Otieno Odhiambo', 'buy', 'vikao_ecommerce', 'Negotiation', 'TH-03', 18500000], ['Achieng Awino', 'buy', 'walk_in', 'Site visit', 'TH-05', null],
          ['Mwangi Kariuki', 'rent', 'website', 'Contacted', 'A-104', null], ['Chebet Rotich', 'invest', 'event', 'New lead', null, null], ['Kamau Njenga', 'buy', 'social', 'Reserved', 'TH-02', 20000000],
          ['Fatuma Ali', 'rent', 'call', 'Lost', 'B-303', null], ['Hassan Omar', 'short_stay', 'website', 'New lead', 'A-301', null]] as $i => [$name, $int, $src, $st, $no, $price]) {
    [$fn, $ln] = explode(' ', $name);
    $id = insert('sls_leads', ['lead_no' => next_no('lead'), 'full_name' => $name, 'phone' => '07' . mt_rand(10000000, 29999999), 'email' => strtolower($fn) . '@example.com', 'source' => $src, 'interest' => $int,
        'property_id' => $no ? (int)val("SELECT property_id FROM prpt_units WHERE id = ?", [$U[$no]]) : null, 'unit_id' => $no ? $U[$no] : null, 'budget_min' => $int === 'buy' ? 15000000 : 40000, 'budget_max' => $int === 'buy' ? 21000000 : 70000,
        'financing' => $int === 'buy' ? ['mortgage', 'cash', 'installments'][$i % 3] : 'undecided', 'stage_id' => $stage($st), 'score' => mt_rand(2, 5), 'assigned_to' => $S['brian'],
        'next_follow_up_at' => in_array($st, ['Won', 'Lost'], true) ? null : date('Y-m-d 10:00:00', strtotime(($i % 3 - 1) . ' days')), 'lost_reason' => $st === 'Lost' ? 'Chose a cheaper unit in South B' : null, 'created_by' => $S['brian'], 'created_at' => date('Y-m-d H:i:s', strtotime('-' . (40 - $i * 4) . ' days'))]);
    lead_log($id, 'call', 'Intro call — interested in ' . ($no ?: 'options') . ', shared brochure', ['outcome' => 'positive', 'done_at' => date('Y-m-d H:i:s', strtotime('-' . (38 - $i * 4) . ' days'))]);
    if (in_array($st, ['Site visit', 'Negotiation', 'Reserved', 'Won'], true)) lead_log($id, 'site_visit', 'Viewed the unit with spouse; liked the finishes', ['outcome' => 'positive', 'done_at' => date('Y-m-d H:i:s', strtotime('-' . (30 - $i * 3) . ' days'))]);
    if (!in_array($st, ['Won', 'Lost'], true)) insert('sls_activities', ['lead_id' => $id, 'activity_type' => 'call', 'summary' => 'Follow up on financing / viewing date', 'due_at' => date('Y-m-d 10:00:00', strtotime(($i % 3 - 1) . ' days')), 'user_id' => $S['brian']]);
    $leads[$name] = [$id, $no, $price, $st];
}
foreach ($leads as $name => [$lid, $no, $price, $st]) {
    if (!$price) continue;
    $deal = deal_create($lid, ['unit_id' => $U[$no], 'agreed_price' => $price, 'deposit_amount' => $price * 0.1, 'payment_plan' => $st === 'Reserved' ? 'installments' : 'mortgage', 'status' => $st === 'Reserved' ? 'agreement_signed' : 'offer', 'expected_close_date' => date('Y-m-d', strtotime('+45 days'))]);
    insert('sls_commission_splits', ['deal_id' => $deal['id'], 'party_id' => $person('Kiprono', 'Agent' . $lid, []), 'share_pct' => 30, 'amount' => round2($deal['commission_amount'] * 0.3)]);
    q("UPDATE sls_commission_splits SET share_pct = 70, amount = ROUND(? * 0.7, 2) WHERE deal_id = ? AND user_id IS NOT NULL", [$deal['commission_amount'], $deal['id']]);
    if ($st === 'Won') { deal_complete((int)$deal['id']); q("UPDATE sls_commission_splits SET status = 'paid', paid_at = NOW() WHERE deal_id = ?", [$deal['id']]); }
    if ($st === 'Reserved') {
        $buyer = $person('Kamau', 'Njenga', ['is_buyer' => 1]); q("UPDATE sls_leads SET party_id = ? WHERE id = ?", [$buyer, $lid]); q("UPDATE sls_deals SET buyer_party_id = ?, status = 'paying' WHERE id = ?", [$buyer, $deal['id']]);
        $inv = invoice_create(['party_id' => $buyer, 'property_id' => $KG, 'unit_id' => $U[$no], 'deal_id' => (int)$deal['id'], 'issue_date' => date('Y-m-d', strtotime('-20 days')), 'due_date' => date('Y-m-d', strtotime('-6 days')), 'source' => 'sale', 'notes' => 'Deal ' . $deal['deal_no']],
            [['bill_type_id' => $bt('SALE_INST'), 'description' => '10% deposit — TH-02 (' . $deal['deal_no'] . ')', 'quantity' => 1, 'unit_price' => $price * 0.1]]);
        $p = payment_record(['account_id' => $coopAcc, 'method' => 'rtgs', 'reference' => 'RTGS' . random_code(8, '0123456789'), 'amount' => $price * 0.1, 'party_id' => $buyer, 'unit_id' => $U[$no], 'paid_at' => date('Y-m-d 11:00:00', strtotime('-8 days'))]);
        payment_auto_allocate((int)$p['id']);
        portal_ensure_account($buyer);
    }
}
$say(count($leads) . ' sales leads, 3 deals (1 completed → ownership transferred)');

/* ---------------- short stay ---------------- */
$lst = [];
foreach (['A-301' => ['Skyline 3-bed suite with pool view', 9500, 11500], 'A-302' => ['Cosy family apartment · Kilimani', 8000, 9500]] as $no => [$title, $rate, $wk]) {
    $own = unit_primary_owner_id($U[$no]); set_unit_status($U[$no], 'occupied', 'Short-stay listing');
    $lst[$no] = insert('sst_listings', ['unit_id' => $U[$no], 'title' => $title, 'max_guests' => 6, 'beds' => 3, 'base_rate' => $rate, 'weekend_rate' => $wk, 'weekly_discount_pct' => 10, 'monthly_discount_pct' => 25, 'cleaning_fee' => 2500, 'security_deposit' => 10000,
        'extra_guest_fee' => 1000, 'extra_guest_after' => 4, 'min_nights' => 2, 'check_in_time' => '14:00:00', 'check_out_time' => '10:00:00', 'house_rules' => "No smoking indoors\nNo parties\nQuiet hours 10pm – 7am", 'wifi_name' => 'Vikao-' . str_replace('-', '', $no), 'wifi_password' => 'karibu' . mt_rand(1000, 9999),
        'access_instructions' => "Collect keys from the gate (show your booking no.). Lift to the 3rd floor, unit $no on the left.", 'tourism_levy_pct' => 2, 'vat_pct' => 0, 'owner_share_pct' => 80, 'ical_export_token' => bin2hex(random_bytes(16)), 'status' => 'active']);
    insert('sst_channels', ['listing_id' => $lst[$no], 'channel' => 'airbnb', 'commission_pct' => 15, 'is_active' => 1]); insert('sst_channels', ['listing_id' => $lst[$no], 'channel' => 'booking_com', 'commission_pct' => 17, 'is_active' => 1]);
    insert('sst_rate_rules', ['listing_id' => $lst[$no], 'name' => 'December holidays', 'start_date' => '2026-12-18', 'end_date' => '2027-01-03', 'nightly_rate' => $rate * 1.4, 'min_nights' => 3]);
}
$guests = [['Linda', 'Smith', 'British'], ['Arjun', 'Patel', 'Indian'], ['Wambui', 'Karanja', 'Kenyan'], ['Mohamed', 'Abdi', 'Somali'], ['Chloe', 'Martin', 'French'], ['Kwame', 'Mensah', 'Ghanaian'], ['Aisha', 'Bello', 'Nigerian'], ['Lars', 'Nilsson', 'Swedish']];
$bk = 0;
foreach ([['A-301', -40, 4, 'airbnb'], ['A-301', -30, 6, 'direct'], ['A-302', -35, 3, 'booking_com'], ['A-302', -20, 7, 'airbnb'], ['A-301', -12, 5, 'vikao'], ['A-302', -3, 5, 'direct'], ['A-301', 3, 4, 'airbnb'], ['A-302', 6, 3, 'booking_com'], ['A-301', 15, 10, 'direct']] as $i => [$no, $off, $n, $ch]) {
    [$gf, $gl, $nat] = $guests[$i % count($guests)];
    $in = date('Y-m-d', strtotime("$off days")); $out = date('Y-m-d', strtotime($in . " +$n days"));
    try { $b = sst_create_booking(['listing_id' => $lst[$no], 'check_in' => $in, 'check_out' => $out, 'adults' => 2, 'children' => $i % 3 === 0 ? 1 : 0, 'channel' => $ch, 'channel_ref' => $ch !== 'direct' ? strtoupper($ch[0]) . mt_rand(100000, 999999) : null,
        'guest_first_name' => $gf, 'guest_last_name' => $gl, 'guest_email' => strtolower($gf) . '.' . strtolower($gl) . '@example.com', 'guest_phone' => '+2547' . mt_rand(10000000, 99999999), 'guest_nationality' => $nat, 'guest_id_number' => 'P' . mt_rand(1000000, 9999999), 'status' => 'confirmed']); } catch (Throwable $e) { continue; }
    $bk++;
    q("UPDATE sst_bookings SET created_at = ? WHERE id = ?", [date('Y-m-d H:i:s', strtotime($in . ' -' . mt_rand(5, 25) . ' days')), $b['id']]);
    $st = $out <= $today ? 'checked_out' : ($in <= $today ? 'checked_in' : 'confirmed');
    q("UPDATE sst_bookings SET status = ?, checked_in_at = IF(? IN ('checked_in','checked_out'), DATE_ADD(check_in, INTERVAL 920 MINUTE), NULL), checked_out_at = IF(? = 'checked_out', DATE_ADD(check_out, INTERVAL 580 MINUTE), NULL), review_rating = IF(? = 'checked_out', ?, NULL), review_text = IF(? = 'checked_out', 'Spotless and great location!', NULL) WHERE id = ?", [$st, $st, $st, $st, mt_rand(4, 5), $st, $b['id']]);
    q("UPDATE sst_housekeeping SET status = IF(scheduled_for < NOW(), 'done', 'pending'), completed_at = IF(scheduled_for < NOW(), scheduled_for, NULL), assigned_to = ? WHERE booking_id = ?", [$S['lucy'], $b['id']]);
    $inv = one("SELECT * FROM bln_invoices WHERE id = ?", [$b['invoice_id']]);
    if ($st !== 'confirmed' || $i % 2 === 0) {
        $p = payment_record(['account_id' => $ch === 'direct' ? $mpesaAcc : (int)val("SELECT id FROM fin_accounts WHERE code = 'VK-STRIPE'"), 'method' => $ch === 'direct' ? 'mpesa' : 'card', 'reference' => ($ch === 'direct' ? 'S' : 'pi_') . random_code(10), 'amount' => (float)$inv['total'], 'party_id' => $inv['party_id'], 'unit_id' => $inv['unit_id'], 'paid_at' => date('Y-m-d H:i:s', strtotime($in . ' -2 days'))]);
        payment_allocate((int)$p['id'], array_map(fn($l) => ['line_id' => (int)$l['id'], 'amount' => (float)$l['balance']], invoice_lines_with_balance((int)$inv['id'])));
    }
}
insert('sst_blocked_dates', ['listing_id' => $lst['A-302'], 'start_date' => date('Y-m-d', strtotime('+20 days')), 'end_date' => date('Y-m-d', strtotime('+24 days')), 'reason' => 'owner_stay', 'note' => 'Owner family visit']);
$say("2 short-stay listings, $bk bookings");

/* ---------------- readings workflow: an open field assignment ---------------- */
$bMeters = col("SELECT m.id FROM prpt_meters m JOIN prpt_units u ON u.id = m.unit_id JOIN prpt_blocks b ON b.id = u.block_id WHERE b.code = 'B' AND b.property_id = ?", [$KH]);
$aid = assignment_create(['title' => 'Block B water — December billing', 'property_id' => $KH, 'bill_type_id' => $bt('WATER'), 'assigned_to' => $S['joseph'], 'period_month' => '2026-12', 'due_date' => date('Y-m-d', strtotime('+5 days')), 'require_photo' => 1, 'notes' => 'Meter room key is with Hassan at the gate'], $bMeters);
$say('open reading assignment for the field reader');

/* ---------------- comms ---------------- */
$cid = insert('com_campaigns', ['name' => 'Water interruption notice — Kilimani Heights', 'channel' => 'both', 'subject' => 'Water interruption on 20 November', 'body' => '<p>Dear {{first_name}},</p><p>Nairobi Water will interrupt supply to <b>{{property_name}}</b> on <b>20 November, 9am – 3pm</b>. Please store enough water. The borehole will serve common areas.</p><p>— {{company_name}}</p>',
    'audience' => json_encode(['type' => 'tenants', 'property_ids' => [$KH]]), 'status' => 'draft', 'created_by' => $S['faith']]);
campaign_send($cid);
foreach (col("SELECT id FROM bln_invoices WHERE period_month = '2026-11' AND source = 'auto'") as $iid) invoice_send((int)$iid);
$say('campaign + November invoices sent (simulated email / SMS)');

/* ---------------- portal logins ---------------- */
$logins = ['jane' => $partyOf['A-G01'], 'tom' => $partyOf['A-G02'], 'peter' => $peter, 'sarah' => $sarah, 'ruth' => $ruth, 'ahmed' => $ahmed, 'acme' => $partyOf['101'], 'esther' => $partyOf['A-101']];
foreach ($logins as $user => $pid) {
    $a = portal_ensure_account((int)$pid);
    update('prt_accounts', ['username' => $user, 'password_hash' => $PORTAL_PW, 'status' => 'active', 'activated_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(20, 200) . ' days')), 'activation_sent_at' => date('Y-m-d H:i:s', strtotime('-210 days')),
        'last_login_at' => date('Y-m-d H:i:s', strtotime('-' . mt_rand(1, 72) . ' hours')), 'app_installed_at' => mt_rand(0, 1) ? date('Y-m-d H:i:s', strtotime('-30 days')) : null], 'id = :id', ['id' => $a['id']]);
}
q("UPDATE prt_accounts SET activation_sent_at = DATE_SUB(NOW(), INTERVAL 3 DAY) WHERE status = 'pending' AND id % 3 = 0");
$say('portal logins: ' . implode(', ', array_keys($logins)) . ' (password Portal@2026)');

/* ---------------- tidy ---------------- */
q("UPDATE sys_notifications SET read_at = NOW() WHERE created_at < DATE_SUB(NOW(), INTERVAL 2 DAY)");
q("UPDATE prt_notifications SET read_at = NOW() WHERE created_at < DATE_SUB(NOW(), INTERVAL 7 DAY)");
generate_insights('portfolio', null, false);
echo "• demo data ready in " . round(microtime(true) - $t0, 1) . "s\n";
echo "  Staff logins (password Vikao@2026): grace (manager), daniel (accounts), joseph (meter reader), faith (care), brian (sales), lucy (short stay), kevin (marketplace) · admin / Admin@2026\n";
