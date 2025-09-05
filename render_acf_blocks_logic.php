<?

function render_acf_block($block_name, $row, $prefix, $field_map) {
    $data = [];
    foreach ($row as $key => $value) {
        if (strpos($key, $prefix) === 0 && $value !== '' && $value !== '[]') {
            $short_key = str_replace($prefix, '', $key);
            $data[$short_key] = $value;
            if (isset($field_map[$key])) {
                $data["_" . $short_key] = $field_map[$key];
            }
        }
    }
    if (empty($data)) return ''; // 🔴 важная проверка

    return '<!-- wp:acf/' . $block_name . ' ' . 
        json_encode([
            "name" => "acf/" . $block_name,
            "data" => $data,
            "mode" => "preview"
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ' /-->' . "\n\n";
}

$field_map = [
    // Package
    "blocks.package.badge"   => "field_packaging_badge",
    "blocks.package.heading" => "field_packaging_heading",
    "blocks.package.packaging_options" => "field_packaging_options",

    // Price
    "blocks.price.badge"   => "field_68489990c2a43",
    "blocks.price.heading" => "field_684899b1c2a44",
    "blocks.price.delivery_options" => "field_684899c2c2a45",

    // Docs
    "blocks.docs.heading"        => "field_68489b44ecdfe",
    "blocks.docs.documents_list" => "field_68489b82ecdff",

    // What-in-price
    "blocks.what_in_price.what_in_price.heading"      => "field_pricing_heading",
    "blocks.what_in_price.what_in_price.description"  => "field_pricing_description",
    "blocks.what_in_price.what_in_price.list_title"   => "field_pricing_list_title",
    "blocks.what_in_price.what_in_price.services_list"=> "field_pricing_services_list",

    // Coop-state
    "blocks.coop_state.heading"  => "field_cooperation_heading",
    "blocks.coop_state.sections" => "field_cooperation_sections",

    // Routes
    "blocks.routes.badge"   => "field_routes_badge",
    "blocks.routes.heading" => "field_routes_heading",
    "blocks.routes.routes"  => "field_routes_routes",

    // FAQ
    "blocks.faq.faq" => "field_680608882e5d3",

    // End-block
    "blocks.end_block.badge"        => "field_order_delivery_badge",
    "blocks.end_block.heading"      => "field_order_delivery_heading",
    "blocks.end_block.description"  => "field_order_delivery_description",
    "blocks.end_block.list_heading" => "field_order_delivery_list_heading",
    "blocks.end_block.challenges_list" => "field_order_delivery_challenges_list",

    // End-block вложенный
    "blocks.end_block.end_block.badge"        => "field_order_delivery_badge",
    "blocks.end_block.end_block.heading"      => "field_order_delivery_heading",
    "blocks.end_block.end_block.description"  => "field_order_delivery_description",
    "blocks.end_block.end_block.list_heading" => "field_order_delivery_list_heading",
    "blocks.end_block.end_block.challenges_list" => "field_order_delivery_challenges_list",

    // Routes вложенные
    "blocks.routes.route.badge"   => "field_routes_badge",
    "blocks.routes.route.heading" => "field_routes_heading",
    "blocks.routes.route.routes"  => "field_routes_routes"
];




