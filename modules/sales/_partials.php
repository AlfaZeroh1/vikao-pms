<?php
function lead_modal_html(): string {
    $stages = all("SELECT id, name FROM sls_stages ORDER BY sort_order");
    $body = '<input type="hidden" name="id"><div class="grid grid-cols-1 sm:grid-cols-3 gap-4">'
        . ui_field('Full name *', ui_input('full_name', '', 'text', 'required')) . ui_field('Phone', ui_input('phone', '', 'tel')) . ui_field('Email', ui_input('email', '', 'email'))
        . ui_field('Interested in *', ui_select('interest', ui_kv_options(['buy' => 'Buying', 'rent' => 'Renting', 'invest' => 'Investing', 'short_stay' => 'Short stay']))) . ui_field('Source *', ui_select('source', ui_kv_options(['walk_in' => 'Walk-in', 'referral' => 'Referral', 'vikao_ecommerce' => 'vikao.io marketplace', 'website' => 'Website', 'social' => 'Social media', 'call' => 'Phone call', 'event' => 'Event / open house', 'agent' => 'External agent', 'other' => 'Other'])))
        . ui_field('Stage', ui_select('stage_id', ui_options($stages, 'id', 'name')))
        . ui_field('Property', ui_select('property_id', ui_options(properties_for_select(), 'id', 'name', null, 'Any'))) . ui_field('Specific unit', ui_unit_picker('unit_id')) . ui_field('Financing', ui_select('financing', ui_kv_options(['undecided' => 'Undecided', 'cash' => 'Cash', 'mortgage' => 'Mortgage', 'installments' => 'Instalments'])))
        . ui_field('Budget from', ui_input('budget_min', '', 'number')) . ui_field('Budget to', ui_input('budget_max', '', 'number')) . ui_field('Lead score (1-5)', ui_input('score', '', 'number', 'min="1" max="5"'))
        . ui_field('Assigned to', ui_select('assigned_to', ui_options(staff_for_select(), 'id', 'name', staff_id()))) . ui_field('Next follow-up', ui_input('next_follow_up_at', '', 'datetime-local'))
        . '<div class="sm:col-span-3">' . ui_field('Notes', '<textarea name="notes" class="vk-textarea" rows="2"></textarea>') . '</div></div>';
    return ui_modal('m-lead', 'Lead', $body, url('api.php', ['r' => 'sales/save-lead']), 'Save lead', 'max-w-4xl', 'fa-user-plus');
}
