<?php
/** Shared modals for the property module. */
function unit_types(): array { return array_column(all("SELECT code, label FROM cfg_lookups WHERE list_key = 'unit_type' AND is_active = 1 ORDER BY sort_order, label"), 'label', 'code'); }

function unit_modal_html(?int $propertyId = null): string {
    $props = properties_for_select();
    $blocks = $propertyId ? all("SELECT id, CONCAT(code, ' — ', name) AS name FROM prpt_blocks WHERE property_id = ? ORDER BY sort_order, code", [$propertyId]) : [];
    $floors = $propertyId ? all("SELECT f.id, CONCAT(COALESCE(b.code, ''), IF(b.code IS NULL, '', ' · '), f.name) AS name FROM prpt_floors f LEFT JOIN prpt_blocks b ON b.id = f.block_id WHERE f.property_id = ? ORDER BY b.code, f.level", [$propertyId]) : [];
    $body = '<input type="hidden" name="id"><div class="grid grid-cols-1 sm:grid-cols-3 gap-4">'
        . ($propertyId ? '<input type="hidden" name="property_id" value="' . $propertyId . '">' : ui_field('Property *', ui_select('property_id', ui_options($props, 'id', 'name', null, 'Choose…'), 'required'), 'sm:col-span-3'))
        . ui_field('Unit number *', ui_input('unit_no', '', 'text', 'required')) . ui_field('Block', ui_select('block_id', ui_options($blocks, 'id', 'name', null, '—'))) . ui_field('Floor', ui_select('floor_id', ui_options($floors, 'id', 'name', null, '—')))
        . ui_field('Unit type', ui_select('unit_type', ui_kv_options(unit_types(), 'apartment'))) . ui_field('Usage', ui_select('usage_class', ui_kv_options(['residential' => 'Residential', 'commercial' => 'Commercial'])))
        . ui_field('Purpose', ui_select('purpose', ui_kv_options(['rent' => 'For rent', 'sale' => 'For sale', 'rent_and_sale' => 'Rent or sale', 'owner_occupied' => 'Home owned / owner occupied', 'short_stay' => 'Short stay'])))
        . ui_field('Status', ui_select('status', ui_kv_options(['vacant' => 'Vacant', 'occupied' => 'Occupied', 'owner_occupied' => 'Owner occupied', 'reserved' => 'Reserved', 'sold' => 'Sold', 'under_maintenance' => 'Under maintenance', 'unavailable' => 'Unavailable'])))
        . ui_field('Bedrooms', ui_input('bedrooms', '', 'number')) . ui_field('Bathrooms', ui_input('bathrooms', '', 'number')) . ui_field('Size (m²)', ui_input('size_sqm', '', 'number', 'step="any"'))
        . ui_field('Furnishing', ui_select('furnishing', ui_kv_options(['unfurnished' => 'Unfurnished', 'semi_furnished' => 'Semi furnished', 'furnished' => 'Furnished'], null, '—')))
        . ui_field('Monthly rent (KSh)', ui_input('rent_amount', '', 'number', 'step="any"')) . ui_field('Service charge (KSh / mo)', ui_input('service_charge', '', 'number', 'step="any"'))
        . ui_field('Deposit (months of rent)', ui_input('deposit_months', '1', 'number', 'step="0.5"')) . ui_field('Sale price (KSh)', ui_input('sale_price', '', 'number', 'step="any"'))
        . '<div class="sm:col-span-3">' . ui_field('Notes', '<textarea name="notes" class="vk-textarea" rows="2"></textarea>') . '</div></div>';
    return ui_modal('m-unit', 'Unit', $body, url('api.php', ['r' => 'property/save-unit']), 'Save unit', 'max-w-3xl', 'fa-door-open');
}

function ownership_modal_html(int $propertyId, ?int $partyId = null, ?int $unitId = null): string {
    $blocks = all("SELECT id, CONCAT(code, ' — ', name) AS name FROM prpt_blocks WHERE property_id = ?", [$propertyId]);
    $body = '<input type="hidden" name="property_id" value="' . $propertyId . '"><div class="grid grid-cols-1 sm:grid-cols-2 gap-4">'
        . ui_field('Owner *', $partyId ? '<input type="hidden" name="party_id" value="' . $partyId . '"><input class="vk-input opacity-70" disabled value="' . e(val("SELECT display_name FROM prpt_parties WHERE id = ?", [$partyId])) . '">' : ui_party_picker('party_id', null, ''), 'sm:col-span-2')
        . ui_field('Owns', ui_select('scope', ui_kv_options(['unit' => 'A unit', 'block' => 'A whole block', 'property' => 'The whole property'], $unitId ? 'unit' : 'unit'), 'data-scope'))
        . ui_field('Share %', ui_input('share_pct', 100, 'number', 'step="0.001" min="0.001" max="100"'))
        . '<div data-scope-unit>' . ui_field('Unit', $unitId ? '<input type="hidden" name="unit_id" value="' . $unitId . '"><input class="vk-input opacity-70" disabled value="' . e(val("SELECT unit_no FROM prpt_units WHERE id = ?", [$unitId])) . '">' : ui_unit_picker('unit_id', null, 'data-property="' . $propertyId . '"')) . '</div>'
        . '<div data-scope-block class="hidden">' . ui_field('Block', ui_select('block_id', ui_options($blocks, 'id', 'name', null, 'Choose…'))) . '</div>'
        . ui_field('Owned since', ui_input('start_date', date('Y-m-d'), 'date')) . ui_field('Acquired by', ui_select('acquisition', ui_kv_options(['purchase' => 'Purchase', 'developer' => 'Developer', 'inheritance' => 'Inheritance', 'gift' => 'Gift', 'other' => 'Other'])))
        . ui_field('Title / sale agreement ref', ui_input('title_ref')) . '<div class="pt-6"><label class="vk-switch"><input type="checkbox" name="is_primary_contact" value="1" checked>Primary contact</label></div>'
        . '</div><p class="text-xs text-muted">Co-owners: add each person with their share — shares on the same unit / block / property must total 100%.</p>';
    return ui_modal('m-own', 'Add ownership', $body, url('api.php', ['r' => 'property/save-ownership']), 'Save ownership', 'max-w-2xl', 'fa-key');
}

function party_modal_html(): string {
    $body = '<input type="hidden" name="id"><div class="flex flex-wrap gap-2"><label class="vk-choice"><input type="radio" name="party_type" value="individual" checked><span><i class="fa-solid fa-user"></i>Individual</span></label><label class="vk-choice"><input type="radio" name="party_type" value="company"><span><i class="fa-solid fa-building"></i>Company</span></label>'
        . '<span class="ml-auto flex gap-2"><label class="vk-choice"><input type="checkbox" name="is_owner" value="1"><span><i class="fa-solid fa-key"></i>Home owner</span></label><label class="vk-choice"><input type="checkbox" name="is_tenant" value="1"><span><i class="fa-solid fa-house-user"></i>Tenant</span></label></span></div>'
        . '<div class="grid grid-cols-1 sm:grid-cols-3 gap-4">'
        . '<div data-ind>' . ui_field('First name *', ui_input('first_name')) . '</div><div data-ind>' . ui_field('Last name', ui_input('last_name')) . '</div><div data-co class="hidden sm:col-span-2">' . ui_field('Company name *', ui_input('company_name')) . '</div>'
        . ui_field('ID type', ui_select('id_type', ui_kv_options(['national_id' => 'National ID', 'passport' => 'Passport', 'alien_id' => 'Alien ID', 'company_reg' => 'Company reg. no.'], null, '—')))
        . ui_field('ID / reg. number', ui_input('id_number')) . ui_field('KRA PIN', ui_input('kra_pin')) . ui_field('Phone', ui_input('phone', '', 'tel', 'placeholder="0712 345 678"'))
        . ui_field('Email', ui_input('email', '', 'email')) . ui_field('Alt. phone', ui_input('alt_phone')) . ui_field('Date of birth', ui_input('date_of_birth', '', 'date'))
        . ui_field('Gender', ui_select('gender', ui_kv_options(['female' => 'Female', 'male' => 'Male', 'other' => 'Other'], null, '—'))) . ui_field('Nationality', ui_input('nationality', 'Kenyan'))
        . ui_field('Occupation', ui_input('occupation')) . ui_field('Employer', ui_input('employer')) . ui_field('Prefers', ui_select('preferred_channel', ui_kv_options(['email' => 'Email', 'sms' => 'SMS', 'whatsapp' => 'WhatsApp', 'portal' => 'Portal'])))
        . ui_field('Postal address', ui_input('postal_address')) . '<div class="sm:col-span-2">' . ui_field('Physical address', ui_input('physical_address')) . '</div>'
        . ui_field('Next of kin', ui_input('next_of_kin_name')) . ui_field('Next of kin phone', ui_input('next_of_kin_phone')) . ui_field('Relationship', ui_input('next_of_kin_relation'))
        . '<div class="sm:col-span-3">' . ui_field('Notes', '<textarea name="notes" class="vk-textarea" rows="2"></textarea>') . '</div></div>'
        . '<label class="vk-switch"><input type="checkbox" name="send_invite" value="1" checked>Email a self-service portal activation link now</label>';
    return ui_modal('m-party', 'Owner / tenant', $body, url('api.php', ['r' => 'property/save-party']), 'Save', 'max-w-4xl', 'fa-user-plus');
}

function document_modal_html(string $ownerType, int $ownerId, ?int $partyId = null): string {
    $body = '<input type="hidden" name="owner_type" value="' . e($ownerType) . '"><input type="hidden" name="owner_id" value="' . $ownerId . '">' . ($partyId ? '<input type="hidden" name="party_id" value="' . $partyId . '">' : '')
        . '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">' . ui_field('Title *', ui_input('title')) . ui_field('Type', ui_select('doc_type', ui_kv_options(['title_deed' => 'Title deed', 'sale_agreement' => 'Sale agreement', 'lease' => 'Lease', 'mgmt_agreement' => 'Management agreement', 'id_copy' => 'ID copy', 'kra' => 'KRA certificate', 'notice' => 'Notice', 'statement' => 'Statement', 'photo' => 'Photo', 'other' => 'Other'], 'other')))
        . '<div class="sm:col-span-2">' . ui_field('File (PDF, image, Word, Excel · max 8 MB)', '<input type="file" name="file" class="vk-input" required>') . '</div></div>'
        . '<label class="vk-switch"><input type="checkbox" name="visible_in_portal" value="1" checked>Visible in the self-service portal</label>';
    return ui_modal('m-doc', 'Upload document', $body, url('api.php', ['r' => 'property/upload-document']), 'Upload', 'max-w-xl', 'fa-file-arrow-up');
}

function documents_table(string $where, array $p): string {
    $docs = all("SELECT d.*, CONCAT(u.first_name, ' ', COALESCE(u.last_name, '')) AS by_name FROM prpt_documents d LEFT JOIN sys_users u ON u.id = d.created_by WHERE $where ORDER BY d.created_at DESC", $p);
    if (!$docs) return ui_empty('fa-folder-open', 'No documents yet');
    $h = '<table class="vk-table"><thead><tr><th>Document</th><th>Type</th><th>Portal</th><th>Added</th><th></th></tr></thead><tbody>';
    foreach ($docs as $d) {
        $href = $d['file_path'] ? file_url($d['file_path']) : page('property/document', ['id' => $d['id']]);
        $h .= '<tr><td><a href="' . e($href) . '" target="_blank" class="font-semibold hover:text-accent"><i class="fa-solid ' . ($d['file_path'] ? 'fa-paperclip' : 'fa-file-signature') . ' text-accent mr-1.5"></i>' . e($d['title']) . '</a>' . ($d['signed_at'] ? '<span class="ml-2">' . pill('done', 'Signed') . '</span>' : '') . '</td><td>' . e(ucwords(str_replace('_', ' ', $d['doc_type']))) . '</td>'
            . '<td>' . ($d['visible_in_portal'] ? '<i class="fa-solid fa-eye text-emerald-500"></i>' : '<i class="fa-solid fa-eye-slash text-muted"></i>') . '</td><td class="text-xs">' . fdate($d['created_at']) . '<span class="block text-muted">' . e($d['by_name']) . '</span></td>'
            . '<td class="text-right">' . (can('property.documents.manage') ? '<button class="vk-icon-btn !h-8 !w-8" data-action="' . e(url('api.php', ['r' => 'property/delete-document', 'id' => $d['id']])) . '" data-confirm="Delete this document?"><i class="fa-regular fa-trash-can text-xs"></i></button>' : '') . '</td></tr>';
    }
    return $h . '</tbody></table>';
}
