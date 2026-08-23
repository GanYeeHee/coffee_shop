<?php
/**
 * Render a validation error message.
 */
function html_error($errors, $field) {
    if (isset($errors[$field])) {
        return '<span class="error-msg" id="error-' . htmlspecialchars($field) . '">' . htmlspecialchars($errors[$field]) . '</span>';
    }
    return '';
}

/**
 * Generate a standard input field.
 */
function html_input($type, $name, $value = '', $label = '', $placeholder = '', $errors = [], $attributes = []) {
    $val = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    $err_class = isset($errors[$name]) ? 'input-error' : '';

    // A 'class' key merges into the generated class string instead of emitting a second class="" attribute.
    $extra_class = '';
    if (isset($attributes['class'])) {
        $extra_class = ' ' . $attributes['class'];
        unset($attributes['class']);
    }

    // Convert attributes array to string
    $attrs_str = '';
    foreach ($attributes as $key => $val_attr) {
        $attrs_str .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val_attr) . '"';
    }

    $input = '<input type="' . htmlspecialchars($type) . '" name="' . htmlspecialchars($name) . '" id="field-' . htmlspecialchars($name) . '" class="form-control ' . $err_class . htmlspecialchars($extra_class) . '" value="' . $val . '" placeholder="' . htmlspecialchars($placeholder) . '"' . $attrs_str . '>';

    // Password fields get a show/hide toggle; the button is purely a display
    // affordance so it carries tabindex="-1" to stay out of the tab order.
    if ($type === 'password') {
        $input = '<div class="password-field">' . $input
            . '<button type="button" class="password-toggle" tabindex="-1" aria-label="Show password">'
            . '<svg class="icon-eye" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>'
            . '<svg class="icon-eye-off" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.6 21.6 0 0 1 5.06-5.94M9.9 4.24A10.94 10.94 0 0 1 12 4c7 0 11 7 11 7a21.6 21.6 0 0 1-2.16 3.19M14.12 14.12a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>'
            . '</button></div>';
    }

    $html = '<div class="form-group">';
    if ($label) {
        $html .= '<label for="field-' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
    }
    $html .= $input;
    $html .= html_error($errors, $name);
    $html .= '</div>';

    return $html;
}

/**
 * Generate a textarea input.
 */
function html_textarea($name, $value = '', $label = '', $placeholder = '', $errors = [], $attributes = []) {
    $val = htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
    $err_class = isset($errors[$name]) ? 'input-error' : '';
    
    $attrs_str = '';
    foreach ($attributes as $key => $val_attr) {
        $attrs_str .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val_attr) . '"';
    }
    
    $html = '<div class="form-group">';
    if ($label) {
        $html .= '<label for="field-' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
    }
    $html .= '<textarea name="' . htmlspecialchars($name) . '" id="field-' . htmlspecialchars($name) . '" class="form-control ' . $err_class . '" placeholder="' . htmlspecialchars($placeholder) . '"' . $attrs_str . '>' . $val . '</textarea>';
    $html .= html_error($errors, $name);
    $html .= '</div>';
    
    return $html;
}

/**
 * Generate a dropdown selection input.
 */
function html_select($name, $options = [], $selected_value = '', $label = '', $errors = [], $attributes = []) {
    $err_class = isset($errors[$name]) ? 'input-error' : '';
    
    $attrs_str = '';
    foreach ($attributes as $key => $val_attr) {
        $attrs_str .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val_attr) . '"';
    }
    
    $html = '<div class="form-group">';
    if ($label) {
        $html .= '<label for="field-' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
    }
    $html .= '<select name="' . htmlspecialchars($name) . '" id="field-' . htmlspecialchars($name) . '" class="form-control ' . $err_class . '"' . $attrs_str . '>';
    
    foreach ($options as $val => $text) {
        $selected = ($val == $selected_value) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($val) . '"' . $selected . '>' . htmlspecialchars($text) . '</option>';
    }
    
    $html .= '</select>';
    $html .= html_error($errors, $name);
    $html .= '</div>';

    return $html;
}

/**
 * Resolve and clamp the current page number from $_GET['page'].
 * Non-numeric, negative, zero, or missing -> 1.
 */
function paginate_current_page() {
    $page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
    if ($page === false || $page === null || $page < 1) {
        return 1;
    }
    return $page;
}

/**
 * Run a COUNT(*) query (same WHERE/params as the list query, no LIMIT/OFFSET)
 * and derive the pagination window. Clamps an out-of-range requested page
 * down to the last valid page.
 */
function paginate_query($pdo, $count_sql, $params, $per_page) {
    $stmt = $pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_rows = (int) $stmt->fetchColumn();

    $total_pages = max(1, (int) ceil($total_rows / $per_page));
    $page = min(paginate_current_page(), $total_pages);
    $offset = ($page - 1) * $per_page;

    return [
        'page' => $page,
        'per_page' => $per_page,
        'offset' => $offset,
        'total_rows' => $total_rows,
        'total_pages' => $total_pages,
    ];
}

/**
 * Render a prev/next + windowed page-number pager as an HTML string.
 * $query_params should contain the active filters WITHOUT 'page'.
 * Returns '' when there's only one page.
 */
function render_pagination($pg, $base_url, $query_params = []) {
    if ($pg['total_pages'] <= 1) {
        return '';
    }
    $page = $pg['page'];
    $total = $pg['total_pages'];

    $link = function ($p) use ($base_url, $query_params) {
        $qp = $query_params;
        $qp['page'] = $p;
        return htmlspecialchars($base_url . '?' . http_build_query($qp));
    };

    $html = '<nav class="pagination" aria-label="Pagination">';
    $html .= $page > 1
        ? '<a href="' . $link($page - 1) . '" class="btn btn-secondary btn-sm">&laquo; Prev</a>'
        : '<span class="btn btn-secondary btn-sm disabled">&laquo; Prev</span>';

    $window = 2;
    $start = max(1, $page - $window);
    $end = min($total, $page + $window);

    if ($start > 1) {
        $html .= '<a href="' . $link(1) . '" class="btn btn-sm">1</a>';
        if ($start > 2) $html .= '<span class="pagination-ellipsis">&hellip;</span>';
    }
    for ($p = $start; $p <= $end; $p++) {
        $cls = $p === $page ? 'btn btn-sm btn-accent' : 'btn btn-sm btn-secondary';
        $html .= '<a href="' . $link($p) . '" class="' . $cls . '">' . $p . '</a>';
    }
    if ($end < $total) {
        if ($end < $total - 1) $html .= '<span class="pagination-ellipsis">&hellip;</span>';
        $html .= '<a href="' . $link($total) . '" class="btn btn-sm">' . $total . '</a>';
    }

    $html .= $page < $total
        ? '<a href="' . $link($page + 1) . '" class="btn btn-secondary btn-sm">Next &raquo;</a>'
        : '<span class="btn btn-secondary btn-sm disabled">Next &raquo;</span>';
    $html .= '</nav>';
    return $html;
}
?>
