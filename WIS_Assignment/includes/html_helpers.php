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
    
    // Convert attributes array to string
    $attrs_str = '';
    foreach ($attributes as $key => $val_attr) {
        $attrs_str .= ' ' . htmlspecialchars($key) . '="' . htmlspecialchars($val_attr) . '"';
    }
    
    $html = '<div class="form-group">';
    if ($label) {
        $html .= '<label for="field-' . htmlspecialchars($name) . '">' . htmlspecialchars($label) . '</label>';
    }
    $html .= '<input type="' . htmlspecialchars($type) . '" name="' . htmlspecialchars($name) . '" id="field-' . htmlspecialchars($name) . '" class="form-control ' . $err_class . '" value="' . $val . '" placeholder="' . htmlspecialchars($placeholder) . '"' . $attrs_str . '>';
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
