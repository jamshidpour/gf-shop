<?php
/*
Plugin Name: Dynamic Test Display
Description: نمایش داینامیک آزمون‌های روانشناسی بر اساس پارامتر URL با فرم گرویتی فرم
Version: 1.3
Author: وب شیک
Text Domain: dynamic-test-display
*/

if (!defined('ABSPATH')) exit;

define('AZMOON_MANAGER_TABLE', $GLOBALS['wpdb']->prefix . 'azmoon_forms');

// ساخت جدول هنگام فعال‌سازی افزونه
register_activation_hook(__FILE__, function () {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS " . AZMOON_MANAGER_TABLE . " (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        form_id BIGINT UNSIGNED NOT NULL,
        form_slug VARCHAR(191) NOT NULL UNIQUE,
        title VARCHAR(255) DEFAULT NULL,
        time_limit_enabled TINYINT(1) NOT NULL DEFAULT 0,
        duration_minutes INT UNSIGNED DEFAULT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
});

// افزودن آیتم به منوی مدیریت
add_action('admin_menu', function() {
    add_menu_page(
        'مدیریت آزمون‌ها',
        'مدیریت آزمون‌ها',
        'manage_options',
        'test-manager',
        function() {
            include plugin_dir_path(__FILE__) . 'admin/test-manager.php';
        },
        'dashicons-welcome-write-blog',
        25
    );
});

// افزودن زیرمنوی تنظیمات
add_action('admin_menu', function() {
    add_submenu_page(
        'test-manager',
        'تنظیمات آزمون‌ها',
        'تنظیمات',
        'manage_options',
        'dtd-settings',
        'dtd_settings_page_callback'
    );
});

function dtd_settings_page_callback() {
    // بررسی nonce برای امنیت
    if (isset($_POST['dtd_save_settings'])) {
        if (!isset($_POST['dtd_settings_nonce']) || !wp_verify_nonce($_POST['dtd_settings_nonce'], 'dtd_save_settings')) {
            wp_die(__('خطای امنیتی. لطفاً دوباره تلاش کنید.', 'dynamic-test-display'));
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('شما اجازه دسترسی به این صفحه را ندارید.', 'dynamic-test-display'));
        }
        
        $only_logged_in = isset($_POST['dtd_only_logged_in']) ? 1 : 0;
        $only_purchased = isset($_POST['dtd_only_purchased']) ? 1 : 0;
        $redirect_url = isset($_POST['dtd_redirect_url']) ? sanitize_url($_POST['dtd_redirect_url']) : '';
        
        update_option('dtd_only_logged_in', $only_logged_in);
        update_option('dtd_only_purchased', $only_purchased);
        update_option('dtd_redirect_url', $redirect_url);
        
        add_settings_error('dtd_settings', 'dtd_settings_saved', __('تنظیمات با موفقیت ذخیره شد.', 'dynamic-test-display'), 'updated');
    }
    
    $only_logged_in = get_option('dtd_only_logged_in', 1);
    $only_purchased = get_option('dtd_only_purchased', 1);
    $redirect_url = get_option('dtd_redirect_url', '');
    
    // نمایش پیغام‌های خطا/موفقیت
    settings_errors('dtd_settings');
    ?>
    <div class="wrap">
        <h1><?php _e('تنظیمات آزمون‌ها', 'dynamic-test-display'); ?></h1>
        <form method="post" action="">
            <?php wp_nonce_field('dtd_save_settings', 'dtd_settings_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th scope="row"><?php _e('نمایش آزمون تنها پس از ورود به حساب کاربری', 'dynamic-test-display'); ?></th>
                    <td>
                        <input type="checkbox" name="dtd_only_logged_in" value="1" <?php checked($only_logged_in, 1); ?> />
                        <label><?php _e('در صورت فعال بودن، فقط کاربران وارد شده می‌توانند آزمون را مشاهده کنند.', 'dynamic-test-display'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('نمایش آزمون تنها در صورت خرید محصول', 'dynamic-test-display'); ?></th>
                    <td>
                        <input type="checkbox" name="dtd_only_purchased" value="1" <?php checked($only_purchased, 1); ?> />
                        <label><?php _e('در صورت فعال بودن، فقط کاربرانی که محصول آزمون را خریده‌اند می‌توانند آزمون را مشاهده کنند.', 'dynamic-test-display'); ?></label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php _e('لینک بازگشت پس از اتمام آزمون', 'dynamic-test-display'); ?></th>
                    <td>
                        <input type="url" name="dtd_redirect_url" value="<?php echo esc_attr($redirect_url); ?>" class="regular-text" placeholder="https://example.com/my-account" />
                        <p class="description"><?php _e('لینک صفحه‌ای که کاربر پس از اتمام آزمون به آن هدایت شود. اگر خالی باشد، به حساب کاربری هدایت می‌شود.', 'dynamic-test-display'); ?></p>
                    </td>
                </tr>
            </table>
            <?php submit_button(__('ذخیره تنظیمات', 'dynamic-test-display'), 'primary', 'dtd_save_settings'); ?>
        </form>
    </div>
    <?php
}

// محدودیت نمایش آزمون فقط برای کاربران لاگین و خریدار
add_filter('the_content', function($content) {
    global $post;
    if (has_shortcode($content, 'dynamic_test_form')) {
        $only_logged_in = get_option('dtd_only_logged_in', 1);
        $only_purchased = get_option('dtd_only_purchased', 1);
        
        // بررسی لاگین بودن کاربر
        if ($only_logged_in && !is_user_logged_in()) {
            return '<div class="dtd-login-required">' . __('جهت مشاهده آزمون وارد حساب کاربری خود شوید.', 'dynamic-test-display') . '</div>';
        }
        
        // بررسی خرید محصول
        if ($only_purchased && is_user_logged_in()) {
            $slug = isset($_GET['test']) ? sanitize_text_field($_GET['test']) : '';
            if ($slug) {
                $user_id = get_current_user_id();
                $has_bought = false;
                
                if (function_exists('wc_customer_bought_product')) {
                    $product_id = dtd_get_product_id_by_slug($slug);
                    if ($product_id) {
                        $has_bought = wc_customer_bought_product('', $user_id, $product_id);
                    }
                }
                
                if (!$has_bought) {
                    return '<div class="dtd-login-required">' . __('برای مشاهده این آزمون باید محصول مربوطه را خریداری کنید.', 'dynamic-test-display') . '</div>';
                }
            }
        }
    }
    return $content;
});

// Hook برای پردازش ارسال اتوماتیک فرم
add_action('gform_pre_submission', function($form) {
    // بررسی اینکه آیا این ارسال اتوماتیک است
    if (isset($_POST['dtd_auto_submit']) && $_POST['dtd_auto_submit'] === '1') {
        dtd_log("Auto-submit detected for form ID: " . $form['id']);
        
        // پر کردن فیلدهای خالی با مقدار 0
        foreach ($form['fields'] as $field) {
            $field_id = $field->id;
            $input_name = 'input_' . $field_id;
            
            // بررسی انواع مختلف فیلد
            switch ($field->type) {
                case 'radio':
                    if (empty($_POST[$input_name])) {
                        // جستجو برای گزینه با مقدار 0، اگر نبود اولین گزینه
                        $default_value = '0';
                        $has_zero_option = false;
                        
                        foreach ($field->choices as $choice) {
                            if ($choice['value'] === '0') {
                                $has_zero_option = true;
                                break;
                            }
                        }
                        
                        // اگر گزینه 0 وجود نداشت، اولین گزینه را انتخاب کن
                        if (!$has_zero_option && !empty($field->choices)) {
                            $default_value = $field->choices[0]['value'];
                        }
                        
                        $_POST[$input_name] = $default_value;
                        dtd_log("Auto-filled radio field {$field_id} with value: " . $default_value);
                    }
                    break;
                    
                case 'select':
                    if (empty($_POST[$input_name])) {
                        // جستجو برای گزینه با مقدار 0
                        $default_value = '0';
                        $has_zero_option = false;
                        
                        foreach ($field->choices as $choice) {
                            if ($choice['value'] === '0') {
                                $has_zero_option = true;
                                break;
                            }
                        }
                        
                        if (!$has_zero_option && !empty($field->choices)) {
                            $default_value = $field->choices[0]['value'];
                        }
                        
                        $_POST[$input_name] = $default_value;
                        dtd_log("Auto-filled select field {$field_id} with value: " . $default_value);
                    }
                    break;
                    
                case 'text':
                case 'textarea':
                case 'number':
                case 'email':
                case 'phone':
                case 'website':
                    if (empty($_POST[$input_name])) {
                        $_POST[$input_name] = '0';
                        dtd_log("Auto-filled {$field->type} field {$field_id} with value: 0");
                    }
                    break;
                    
                case 'checkbox':
                    // برای checkbox ها باید به صورت متفاوت عمل کرد
                    if ($field->isRequired) {
                        $has_value = false;
                        
                        // بررسی اینکه آیا حداقل یک checkbox انتخاب شده
                        foreach ($field->choices as $index => $choice) {
                            $checkbox_input = 'input_' . $field_id . '.' . ($index + 1);
                            if (!empty($_POST[$checkbox_input])) {
                                $has_value = true;
                                break;
                            }
                        }
                        
                        // اگر هیچ checkbox انتخاب نشده، اولی را انتخاب کن
                        if (!$has_value && !empty($field->choices)) {
                            $first_checkbox = 'input_' . $field_id . '.1';
                            $_POST[$first_checkbox] = $field->choices[0]['value'];
                            dtd_log("Auto-filled required checkbox field {$field_id}");
                        }
                    }
                    break;
            }
        }
        
        // غیرفعال کردن اعتبارسنجی برای فیلدهای الزامی
        add_filter('gform_field_validation', function($result, $value, $form, $field) {
            if (isset($_POST['dtd_auto_submit']) && $_POST['dtd_auto_submit'] === '1') {
                $result['is_valid'] = true;
                $result['message'] = '';
                return $result;
            }
            return $result;
        }, 10, 4);
    }
}, 5);

// جلوگیری از ریدایرکت اتوماتیک برای ارسال اتوماتیک
add_filter('gform_confirmation', function($confirmation, $form, $entry, $ajax) {
    // بررسی اینکه آیا این ارسال اتوماتیک بوده
    if (isset($_POST['dtd_auto_submit']) && $_POST['dtd_auto_submit'] === '1') {
        dtd_log("Auto-submit confirmation for form ID: " . $form['id']);
        
        // بازگرداندن پیام ساده بدون ریدایرکت
        return array(
            'redirect' => '',
            'message' => '<div id="dtd-auto-submit-success" style="display:none;">فرم با موفقیت ارسال شد</div>'
        );
    }
    
    return $confirmation;
}, 10, 4);

// گرفتن اطلاعات فرم‌ها از جدول دیتابیس
function dtd_get_tests($only_active = false) {
    global $wpdb;
    $table = AZMOON_MANAGER_TABLE;
    
    try {
        $where = $only_active ? 'WHERE is_active = 1' : '';
        $results = $wpdb->get_results($wpdb->prepare("SELECT * FROM {$table} {$where}"), ARRAY_A);
        
        if ($wpdb->last_error) {
            error_log('DTD Plugin DB Error: ' . $wpdb->last_error);
            return [];
        }
        
        $tests = [];
        foreach ($results as $row) {
            $tests[$row['form_slug']] = [
                'form_id' => (int) $row['form_id'],
                'slug' => sanitize_text_field($row['form_slug']),
                'title' => sanitize_text_field($row['title']),
                'duration' => (int) $row['duration_minutes'],
                'time_limit_enabled' => (bool) $row['time_limit_enabled'],
                'active' => (bool) $row['is_active'],
            ];
        }
        
        return $tests;
        
    } catch (Exception $e) {
        error_log('DTD Plugin Error in dtd_get_tests: ' . $e->getMessage());
        return [];
    }
}

// شورتکد نمایش آزمون با تمام بهبودهای جدید
add_shortcode('dynamic_test_form', function() {
    $slug = isset($_GET['test']) ? sanitize_text_field($_GET['test']) : null;
    $tests = dtd_get_tests(true);

    if (!$slug || !isset($tests[$slug]) || !$tests[$slug]['active']) {
        return '<p>' . __('آزمون مورد نظر یافت نشد یا غیرفعال است.', 'dynamic-test-display') . '</p>';
    }

    $test = $tests[$slug];
    
    // بررسی وجود فرم در Gravity Forms
    if (!class_exists('GFAPI') || !GFAPI::form_id_exists($test['form_id'])) {
        return '<p>' . __('فرم مربوطه در سیستم یافت نشد.', 'dynamic-test-display') . '</p>';
    }
    
    $redirect_url = get_option('dtd_redirect_url', '');
    if (empty($redirect_url)) {
        $redirect_url = function_exists('wc_get_account_endpoint_url') ? 
            wc_get_account_endpoint_url('dashboard') : 
            home_url('/my-account');
    }
    
    ob_start(); ?>
    <div class="dynamic-test-box">
        <h1><?php echo esc_html($test['title']); ?></h1>
        
        <?php if ($test['time_limit_enabled'] && !empty($test['duration'])): ?>
            <div class="dtd-timer-container">
                <div class="dtd-timer-display">
                    <span class="dtd-timer-label"><?php _e('زمان باقی‌مانده:', 'dynamic-test-display'); ?></span>
                    <span class="dtd-timer" id="dtd-countdown" data-duration="<?php echo intval($test['duration']); ?>">
                        <?php echo sprintf('%02d:%02d', $test['duration'], 0); ?>
                    </span>
                </div>
                <div class="dtd-timer-bar">
                    <div class="dtd-timer-progress" id="dtd-progress"></div>
                </div>
            </div>
        <?php else: ?>
            <?php if (!empty($test['duration'])): ?>
                <p class="test-duration">⏱ <?php printf(__('مدت زمان: %d دقیقه', 'dynamic-test-display'), $test['duration']); ?></p>
            <?php endif; ?>
        <?php endif; ?>
        
        <div class="test-form">
            <?php echo do_shortcode('[gravityform id="' . intval($test['form_id']) . '" title="false" description="false" ajax="true"]'); ?>
        </div>
    </div>

    <!-- مودال اتمام زمان -->
    <div id="dtd-timeout-modal" class="dtd-modal" style="display: none;">
        <div class="dtd-modal-content">
            <div class="dtd-modal-header">
                <h3>⏰ <?php _e('اتمام زمان آزمون', 'dynamic-test-display'); ?></h3>
            </div>
            <div class="dtd-modal-body">
                <p><?php _e('زمان انجام این آزمون به پایان رسید. پاسخ شما به سوالات ثبت شد.', 'dynamic-test-display'); ?></p>
                <div id="dtd-countdown-redirect">
                    <p style="margin-top: 20px; font-weight: bold; color: #0073aa;">
                        <?php _e('شما تا', 'dynamic-test-display'); ?> 
                        <span id="dtd-redirect-timer">10</span> 
                        <?php _e('ثانیه دیگر به مرحله ثبت اطلاعات شخصی منتقل می‌شوید.', 'dynamic-test-display'); ?>
                    </p>
                    <div class="dtd-redirect-progress-bar" style="width: 100%; height: 6px; background: #f0f0f0; border-radius: 3px; margin-top: 10px; overflow: hidden;">
                        <div id="dtd-redirect-progress" style="height: 100%; background: #0073aa; width: 0%; transition: width 1s linear;"></div>
                    </div>
                </div>
            </div>
            <div class="dtd-modal-footer">
                <button id="dtd-redirect-now-btn" class="dtd-modal-btn"><?php _e('انتقال فوری', 'dynamic-test-display'); ?></button>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const timerElement = document.getElementById('dtd-countdown');
        const progressElement = document.getElementById('dtd-progress');
        const modal = document.getElementById('dtd-timeout-modal');
        const redirectNowBtn = document.getElementById('dtd-redirect-now-btn');
        const redirectTimer = document.getElementById('dtd-redirect-timer');
        const redirectProgress = document.getElementById('dtd-redirect-progress');
        
        if (!timerElement) return;
        
        const totalMinutes = parseInt(timerElement.dataset.duration);
        let timeRemaining = totalMinutes * 60;
        const totalSeconds = timeRemaining;
        const redirectUrl = '<?php echo esc_js($redirect_url); ?>';
        let isAutoSubmitting = false;
        
        function updateTimer() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            
            timerElement.textContent = `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
            
            if (progressElement) {
                const progressPercent = ((totalSeconds - timeRemaining) / totalSeconds) * 100;
                progressElement.style.width = progressPercent + '%';
                
                if (timeRemaining <= 60) {
                    progressElement.style.backgroundColor = '#e74c3c';
                    timerElement.style.color = '#e74c3c';
                    timerElement.classList.add('danger');
                } else if (timeRemaining <= 300) {
                    progressElement.style.backgroundColor = '#f39c12';
                    timerElement.style.color = '#f39c12';
                }
            }
            
            if (timeRemaining <= 0) {
                clearInterval(timerInterval);
                submitForm();
                return;
            }
            
            timeRemaining--;
        }
        
        function submitForm() {
            if (isAutoSubmitting) return;
            isAutoSubmitting = true;
            
            try {
                const form = document.querySelector('.gform_wrapper form');
                if (!form) {
                    console.error('فرم یافت نشد');
                    showModalWithCountdown();
                    return;
                }

                // پر کردن فیلدهای خالی با مقدار 0
                fillEmptyFields(form);

                // غیرفعال کردن اعتبارسنجی
                disableClientValidation(form);

                // اضافه کردن فلگ ارسال اتوماتیک
                let hiddenInput = form.querySelector('input[name="dtd_auto_submit"]');
                if (!hiddenInput) {
                    hiddenInput = document.createElement('input');
                    hiddenInput.type = 'hidden';
                    hiddenInput.name = 'dtd_auto_submit';
                    hiddenInput.value = '1';
                    form.appendChild(hiddenInput);
                }

                // جلوگیری از ریدایرکت معمولی Gravity Forms
                if (typeof gform !== 'undefined') {
                    const originalRedirect = gform.redirect;
                    gform.redirect = function() {
                        if (isAutoSubmitting) {
                            console.log('Redirect blocked for auto-submit');
                            return false;
                        }
                        return originalRedirect.apply(this, arguments);
                    };
                }

                // ارسال فرم
                const submitBtn = form.querySelector('input[type="submit"], button[type="submit"]');
                if (submitBtn) {
                    submitBtn.click();
                    
                    // نمایش مودال بعد از مدت کوتاهی
                    setTimeout(() => {
                        showModalWithCountdown();
                    }, 2000);
                } else {
                    showModalWithCountdown();
                }
            } catch (error) {
                console.error('خطا در ارسال فرم:', error);
                showModalWithCountdown();
            }
        }
        
        function fillEmptyFields(form) {
            // پر کردن Radio Buttons
            const radioGroups = {};
            form.querySelectorAll('input[type="radio"]').forEach(radio => {
                if (!radioGroups[radio.name]) {
                    radioGroups[radio.name] = [];
                }
                radioGroups[radio.name].push(radio);
            });

            Object.keys(radioGroups).forEach(groupName => {
                const isChecked = form.querySelector(`input[name="${groupName}"]:checked`);
                if (!isChecked) {
                    // اولویت با گزینه‌ای که مقدار 0 دارد
                    const zeroOption = radioGroups[groupName].find(r => r.value === '0');
                    const defaultOption = zeroOption || radioGroups[groupName][0];
                    
                    if (defaultOption) {
                        defaultOption.checked = true;
                        console.log(`Radio group ${groupName} filled with value: ${defaultOption.value}`);
                    }
                }
            });

            // پر کردن Text Inputs
            form.querySelectorAll('input[type="text"], input[type="number"], textarea, input[type="email"]').forEach(input => {
                if (!input.value.trim()) {
                    input.value = '0';
                    console.log(`Text field ${input.name} filled with: 0`);
                }
            });

            // پر کردن Select Boxes
            form.querySelectorAll('select').forEach(select => {
                if (!select.value || select.value === '') {
                    // اولویت با گزینه‌ای که مقدار 0 دارد
                    const zeroOption = select.querySelector('option[value="0"]');
                    const defaultOption = zeroOption || select.querySelector('option:not([value=""]):not([disabled])');
                    
                    if (defaultOption) {
                        select.value = defaultOption.value;
                        console.log(`Select ${select.name} filled with value: ${defaultOption.value}`);
                    }
                }
            });

            // پر کردن Checkboxes الزامی
            form.querySelectorAll('input[type="checkbox"]').forEach(checkbox => {
                const fieldContainer = checkbox.closest('.gfield');
                if (fieldContainer && fieldContainer.classList.contains('gfield_contains_required')) {
                    const groupName = checkbox.name.replace(/\[\d+\]$/, '');
                    const isAnyChecked = form.querySelector(`input[name*="${groupName}"]:checked`);
                    
                    if (!isAnyChecked && checkbox.value === '0') {
                        checkbox.checked = true;
                        console.log(`Required checkbox ${checkbox.name} checked with value: 0`);
                    }
                }
            });
        }

        function disableClientValidation(form) {
            form.setAttribute('novalidate', 'novalidate');
            
            form.querySelectorAll('.gfield_contains_required').forEach(field => {
                field.classList.remove('gfield_contains_required');
            });
            
            form.querySelectorAll('[required]').forEach(field => {
                field.removeAttribute('required');
            });
        }
        
        function showModalWithCountdown() {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            
            let countdown = 10;
            
            function updateCountdown() {
                redirectTimer.textContent = countdown;
                const progressPercent = ((10 - countdown) / 10) * 100;
                redirectProgress.style.width = progressPercent + '%';
                
                if (countdown <= 0) {
                    window.location.href = redirectUrl;
                    return;
                }
                
                countdown--;
                setTimeout(updateCountdown, 1000);
            }
            
            updateCountdown();
        }
        
        redirectNowBtn.addEventListener('click', function() {
            window.location.href = redirectUrl;
        });
        
        const timerInterval = setInterval(updateTimer, 1000);
        updateTimer();
        
        // جلوگیری از خروج از صفحه در حین آزمون
        window.addEventListener('beforeunload', function(e) {
            if (timeRemaining > 0 && !isAutoSubmitting) {
                const message = '<?php _e("آیا مطمئن هستید که می‌خواهید صفحه را ترک کنید؟ پیشرفت آزمون شما از دست خواهد رفت.", "dynamic-test-display"); ?>';
                e.returnValue = message;
                return message;
            }
        });
    });
    </script>
    <?php
    return ob_get_clean();
});

// بارگذاری CSS
add_action('wp_enqueue_scripts', function() {
    wp_enqueue_style('dynamic-test-style', plugin_dir_url(__FILE__) . 'assets/style.css', [], '1.3');
});

// افزودن دکمه شروع آزمون به صفحه تشکر ووکامرس
add_action('woocommerce_thankyou', function($order_id) {
    if (!$order_id) return;
    $order = wc_get_order($order_id);
    if (!$order) return;
    $tests = dtd_get_tests(true);
    $slugs = array_keys($tests);
    $button_shown = false;
    foreach ($order->get_items() as $item) {
        $product = $item->get_product();
        if (!$product) continue;
        $product_slug = $product->get_slug();
        if (in_array($product_slug, $slugs)) {
            $exam_url = home_url('/exam/?test=' . $product_slug);
            echo '<a href="' . esc_url($exam_url) . '" class="button dtd-exam-btn" style="display:inline-block;margin:20px 0;padding:12px 28px;background:#0073aa;color:#fff;border-radius:6px;font-size:18px;text-decoration:none;">شروع آزمون</a>';
            $button_shown = true;
        }
    }
}, 20);

// افزودن دکمه شروع آزمون به ردیف هر محصول در جدول سفارش ووکامرس
add_action('woocommerce_order_item_meta_end', function($item_id, $item, $order) {
    $product = $item->get_product();
    if (!$product) return;
    $tests = dtd_get_tests(true);
    $slugs = array_keys($tests);
    $product_slug = $product->get_slug();
    if (in_array($product_slug, $slugs)) {
        $exam_url = home_url('/exam/?test=' . $product_slug);
        echo '<br><a href="' . esc_url($exam_url) . '" class="button dtd-exam-btn" style="display:inline-block;margin:10px 0 0 0;padding:8px 20px;background:#0073aa;color:#fff;border-radius:6px;font-size:15px;text-decoration:none;">شروع آزمون</a>';
    }
}, 10, 3);

// تابع کمکی برای پیدا کردن product_id بر اساس slug با کش
function dtd_get_product_id_by_slug($slug) {
    if (empty($slug)) {
        return 0;
    }
    
    // استفاده از کش برای بهبود کارایی
    $cache_key = 'dtd_product_id_' . md5($slug);
    $product_id = wp_cache_get($cache_key, 'dtd_plugin');
    
    if (false === $product_id) {
        $product = get_page_by_path(sanitize_text_field($slug), OBJECT, 'product');
        $product_id = $product ? $product->ID : 0;
        
        // ذخیره در کش برای 1 ساعت
        wp_cache_set($cache_key, $product_id, 'dtd_plugin', HOUR_IN_SECONDS);
    }
    
    return (int) $product_id;
}

// افزودن اکشن برای پاک کردن کش هنگام به‌روزرسانی محصولات
add_action('save_post_product', function($post_id) {
    if (get_post_type($post_id) === 'product') {
        $product = get_post($post_id);
        if ($product && $product->post_name) {
            $cache_key = 'dtd_product_id_' . md5($product->post_name);
            wp_cache_delete($cache_key, 'dtd_plugin');
        }
    }
});

// افزودن تابع لاگ برای اشکال‌زدایی
function dtd_log($message, $level = 'info') {
    if (defined('WP_DEBUG') && WP_DEBUG) {
        $log_message = sprintf('[DTD Plugin] [%s] %s', strtoupper($level), $message);
        error_log($log_message);
    }
}
