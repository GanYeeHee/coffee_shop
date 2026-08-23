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
?>
