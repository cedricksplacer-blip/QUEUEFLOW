<?php $__env->startSection('title', 'Settings'); ?>

<?php $__env->startSection('content'); ?>

<?php
    /*
    |--------------------------------------------------------------------------
    | Safe Settings Defaults
    |--------------------------------------------------------------------------
    | These values prevent Laravel validation from receiving empty queue
    | settings when another setting is changed.
    */

    $siteName = old('site_name', $settings['site_name'] ?? 'QueueFlow TMC');
    $collegeName = old('college_name', $settings['college_name'] ?? 'TMC College');
    $systemDescription = old('system_description', $settings['system_description'] ?? '');

    $allowStudentProfileEdit =
        old('allow_student_profile_edit', $settings['allow_student_profile_edit'] ?? '0');

    $allowChangePassword =
        old('allow_change_password', $settings['allow_change_password'] ?? '0');

    $darkMode =
        old('dark_mode', $settings['dark_mode'] ?? '0') == '1';

    $showSystemLogo =
        old('show_system_logo', $settings['show_system_logo'] ?? '0');

    $maintenanceMode =
        old('maintenance_mode', $settings['maintenance_mode'] ?? '0');

    /*
    |--------------------------------------------------------------------------
    | Security Defaults
    |--------------------------------------------------------------------------
    */

    $sessionTimeout =
        old('session_timeout', $settings['session_timeout'] ?? 30);

    $maxLoginAttempts =
        old('max_login_attempts', $settings['max_login_attempts'] ?? 5);

    $lockoutDuration =
        old('lockout_duration', $settings['lockout_duration'] ?? 15);

    $minimumPasswordLength =
        old('minimum_password_length', $settings['minimum_password_length'] ?? 8);

    /*
    |--------------------------------------------------------------------------
    | Appearance Defaults
    |--------------------------------------------------------------------------
    */

    $primaryColor =
        old('primary_color', $settings['primary_color'] ?? '#073B91');

    $secondaryColor =
        old('secondary_color', $settings['secondary_color'] ?? '#1264D8');

    $accentColor =
        old('accent_color', $settings['accent_color'] ?? '#FFB300');

    /*
    |--------------------------------------------------------------------------
    | Maintenance / Backup
    |--------------------------------------------------------------------------
    */

    $maintenanceMessage =
        old(
            'maintenance_message',
            $settings['maintenance_message']
                ?? 'QueueFlow TMC is currently under maintenance.'
        );

    $dataRetentionPeriod =
        old(
            'data_retention_period',
            $settings['data_retention_period'] ?? 365
        );

    /*
    |--------------------------------------------------------------------------
    | REQUIRED QUEUE SETTINGS
    |--------------------------------------------------------------------------
    | These are the fields shown in your Laravel validation errors.
    | Existing database values are used first.
    */

    $dailyResetTime =
        old(
            'daily_reset_time',
            $settings['daily_reset_time'] ?? '00:00'
        );

    $defaultWaitingTime =
        old(
            'default_waiting_time',
            $settings['default_waiting_time'] ?? 15
        );

    $maxActiveTicketsPerStudent =
        old(
            'max_active_tickets_per_student',
            $settings['max_active_tickets_per_student'] ?? 1
        );

    $queueStartingValue =
        old(
            'queue_starting_value',
            $settings['queue_starting_value'] ?? 1
        );

    $defaultOpeningTime =
        old(
            'default_opening_time',
            $settings['default_opening_time'] ?? '08:00'
        );

    $defaultClosingTime =
        old(
            'default_closing_time',
            $settings['default_closing_time'] ?? '17:00'
        );

    $defaultLunchBreak =
        old(
            'default_lunch_break',
            $settings['default_lunch_break'] ?? '12:00'
        );
?>


<div class="max-w-5xl mx-auto">

    
    
    

    <div class="admin-header mb-5">

        <div>

            <div class="admin-header-label">
                TMC ADMINISTRATION
            </div>

            <h1 class="admin-header-title">
                System Settings
            </h1>

            <p class="admin-header-description">
                Manage QueueFlow TMC system configuration and preferences.
            </p>

        </div>

        <div class="system-status">

            <span class="status-dot"></span>

            <span>
                System Operational
            </span>

        </div>

    </div>


    
    
    

    <?php if(session('success')): ?>

        <div class="message success-message">

            <?php echo e(session('success')); ?>


        </div>

    <?php endif; ?>


    
    
    

    <?php if(session('status')): ?>

        <div class="message success-message">

            <?php echo e(session('status')); ?>


        </div>

    <?php endif; ?>


    
    
    

    <?php if($errors->any()): ?>

        <div class="message error-message">

            <div class="font-semibold mb-1">
                Please correct the following:
            </div>

            <ul class="list-disc list-inside space-y-0.5">

                <?php $__currentLoopData = $errors->all(); $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $error): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>

                    <li>
                        <?php echo e($error); ?>

                    </li>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

            </ul>

        </div>

    <?php endif; ?>


    
    
    
    
    
    

    <div class="settings-card mb-5">

        <div class="card-header">

            <div>

                <h2>
                    My Profile
                </h2>

                <p>
                    Your own account details, contact information, and password.
                </p>

            </div>

        </div>


        <div class="settings-content">

            <div class="flex flex-wrap items-center justify-between gap-4">

                <div class="flex items-center gap-4">

                    <div class="user-avatar">
                        <?php if (isset($component)) { $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $component; } ?>
<?php if (isset($attributes)) { $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b = $attributes; } ?>
<?php $component = Illuminate\View\AnonymousComponent::resolve(['view' => 'components.avatar','data' => ['user' => auth()->user()]] + (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag ? (array) $attributes->getIterator() : [])); ?>
<?php $component->withName('avatar'); ?>
<?php if ($component->shouldRender()): ?>
<?php $__env->startComponent($component->resolveView(), $component->data()); ?>
<?php if (isset($attributes) && $attributes instanceof Illuminate\View\ComponentAttributeBag && $constructor = (new ReflectionClass(Illuminate\View\AnonymousComponent::class))->getConstructor()): ?>
<?php $attributes = $attributes->except(collect($constructor->getParameters())->map->getName()->all()); ?>
<?php endif; ?>
<?php $component->withAttributes(['user' => \Illuminate\View\Compilers\BladeCompiler::sanitizeComponentAttribute(auth()->user())]); ?>
<?php echo $__env->renderComponent(); ?>
<?php endif; ?>
<?php if (isset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $attributes = $__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__attributesOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
<?php if (isset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b)): ?>
<?php $component = $__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b; ?>
<?php unset($__componentOriginal8ca5b43b8fff8bb34ab2ba4eb4bdd67b); ?>
<?php endif; ?>
                    </div>

                    <div>
                        <div class="setting-title">
                            <?php echo e(auth()->user()->name); ?>

                        </div>
                        <div class="setting-value">
                            <?php echo e(auth()->user()->email); ?>

                        </div>
                    </div>

                </div>

                <a
                    href="<?php echo e(route('admin.profile.edit')); ?>"
                    class="btn-primary"
                >
                    Manage My Profile
                </a>

            </div>

        </div>

    </div>


    
    
    

    <form
        method="POST"
        action="<?php echo e(route('admin.settings.update')); ?>"
        id="settingsForm"
    >

        <?php echo csrf_field(); ?>

        <?php echo method_field('PUT'); ?>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        1. General Information
                    </h2>

                    <p>
                        Basic information about your QueueFlow TMC system.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                

                <button
                    type="button"
                    onclick="openSetting('site_name')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            System Name
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="site_name"
                        >
                            <?php echo e($siteName); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('college_name')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            College / Organization Name
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="college_name"
                        >
                            <?php echo e($collegeName); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('system_description')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            System Description
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="system_description"
                        >
                            <?php echo e($systemDescription ?: 'Not configured'); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Allow Students to Edit Profile
                        </div>

                        <div class="setting-value">
                            Allow students to update their profile
                        </div>

                    </div>


                    <label class="switch">

                        <input
                            type="hidden"
                            name="allow_student_profile_edit"
                            value="0"
                        >

                        <input
                            type="checkbox"
                            name="allow_student_profile_edit"
                            value="1"
                            <?php echo e($allowStudentProfileEdit == '1' ? 'checked' : ''); ?>

                        >

                        <span class="slider"></span>

                    </label>

                </div>


                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Allow Students to Change Password
                        </div>

                        <div class="setting-value">
                            Allow students to change their password
                        </div>

                    </div>


                    <label class="switch">

                        <input
                            type="hidden"
                            name="allow_change_password"
                            value="0"
                        >

                        <input
                            type="checkbox"
                            name="allow_change_password"
                            value="1"
                            <?php echo e($allowChangePassword == '1' ? 'checked' : ''); ?>

                        >

                        <span class="slider"></span>

                    </label>

                </div>

            </div>

        </div>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        2. Queue Settings
                    </h2>

                    <p>
                        Configure the default queue behavior and operating hours.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                

                <button
                    type="button"
                    onclick="openSetting('daily_reset_time')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Daily Queue Reset Time
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="daily_reset_time"
                        >
                            <?php echo e($dailyResetTime); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('default_waiting_time')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Default Waiting Time
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="default_waiting_time"
                        >
                            <?php echo e($defaultWaitingTime); ?> minutes
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('max_active_tickets_per_student')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Maximum Active Tickets Per Student
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="max_active_tickets_per_student"
                        >
                            <?php echo e($maxActiveTicketsPerStudent); ?> ticket(s)
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('queue_starting_value')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Queue Starting Value
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="queue_starting_value"
                        >
                            <?php echo e($queueStartingValue); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('default_opening_time')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Default Opening Time
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="default_opening_time"
                        >
                            <?php echo e($defaultOpeningTime); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('default_closing_time')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Default Closing Time
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="default_closing_time"
                        >
                            <?php echo e($defaultClosingTime); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('default_lunch_break')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Default Lunch Break
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="default_lunch_break"
                        >
                            <?php echo e($defaultLunchBreak); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>

            </div>

        </div>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        5. Notification Settings
                    </h2>

                    <p>
                        Control queue and announcement notifications.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                <?php

                    $notifications = [

                        'ticket_created_notification'
                            => 'Ticket Created Notification',

                        'queue_almost_ready_notification'
                            => 'Queue Almost Ready Notification',

                        'now_serving_notification'
                            => 'Now Serving Notification',

                        'ticket_completed_notification'
                            => 'Ticket Completed Notification',

                        'ticket_cancelled_notification'
                            => 'Ticket Cancelled Notification',

                        'announcement_notification'
                            => 'Announcement Notification',

                    ];

                ?>


                <?php $__currentLoopData = $notifications; $__env->addLoop($__currentLoopData); foreach($__currentLoopData as $key => $label): $__env->incrementLoopIndices(); $loop = $__env->getLastLoop(); ?>

                    <div class="setting-row">

                        <div>

                            <div class="setting-title">
                                <?php echo e($label); ?>

                            </div>

                            <div class="setting-value">
                                Notification preference
                            </div>

                        </div>


                        <label class="switch">

                            <input
                                type="hidden"
                                name="<?php echo e($key); ?>"
                                value="0"
                            >

                            <input
                                type="checkbox"
                                name="<?php echo e($key); ?>"
                                value="1"
                                <?php echo e(($settings[$key] ?? '0') == '1' ? 'checked' : ''); ?>

                            >

                            <span class="slider"></span>

                        </label>

                    </div>

                <?php endforeach; $__env->popLoop(); $loop = $__env->getLastLoop(); ?>

            </div>

        </div>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        6. Security Settings
                    </h2>

                    <p>
                        Configure account security settings.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                

                <button
                    type="button"
                    onclick="openSetting('session_timeout')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Session Timeout
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="session_timeout"
                        >
                            <?php echo e($sessionTimeout); ?> minutes
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('max_login_attempts')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Maximum Login Attempts
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="max_login_attempts"
                        >
                            <?php echo e($maxLoginAttempts); ?> attempts
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('lockout_duration')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Account Lockout Duration
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="lockout_duration"
                        >
                            <?php echo e($lockoutDuration); ?> minutes
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('minimum_password_length')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Minimum Password Length
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="minimum_password_length"
                        >
                            <?php echo e($minimumPasswordLength); ?> characters
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>

            </div>

        </div>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        7. System Appearance
                    </h2>

                    <p>
                        Customize the visual appearance of QueueFlow TMC.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                

                <button
                    type="button"
                    onclick="openSetting('primary_color')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Primary Color
                        </div>

                        <div class="color-value">

                            <span
                                id="primaryColorPreview"
                                class="color-preview"
                                style="background-color: <?php echo e($primaryColor); ?>"
                            ></span>

                            <span
                                class="setting-value"
                                data-setting-value="primary_color"
                            >
                                <?php echo e($primaryColor); ?>

                            </span>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('secondary_color')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Secondary Color
                        </div>

                        <div class="color-value">

                            <span
                                id="secondaryColorPreview"
                                class="color-preview"
                                style="background-color: <?php echo e($secondaryColor); ?>"
                            ></span>

                            <span
                                class="setting-value"
                                data-setting-value="secondary_color"
                            >
                                <?php echo e($secondaryColor); ?>

                            </span>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <button
                    type="button"
                    onclick="openSetting('accent_color')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Accent Color
                        </div>

                        <div class="color-value">

                            <span
                                id="accentColorPreview"
                                class="color-preview"
                                style="background-color: <?php echo e($accentColor); ?>"
                            ></span>

                            <span
                                class="setting-value"
                                data-setting-value="accent_color"
                            >
                                <?php echo e($accentColor); ?>

                            </span>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Dark Mode
                        </div>

                        <div class="setting-value">
                            Change the system appearance
                        </div>

                    </div>


                    <label class="switch">

                        <input
                            type="hidden"
                            name="dark_mode"
                            id="darkModeValue"
                            value="<?php echo e($darkMode ? '1' : '0'); ?>"
                        >

                        <input
                            type="checkbox"
                            id="darkModeToggle"
                            value="1"
                            <?php echo e($darkMode ? 'checked' : ''); ?>

                        >

                        <span class="slider"></span>

                    </label>

                </div>


                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Show System Logo
                        </div>

                        <div class="setting-value">
                            Display the system logo where supported
                        </div>

                    </div>


                    <label class="switch">

                        <input
                            type="hidden"
                            name="show_system_logo"
                            value="0"
                        >

                        <input
                            type="checkbox"
                            name="show_system_logo"
                            value="1"
                            <?php echo e($showSystemLogo == '1' ? 'checked' : ''); ?>

                        >

                        <span class="slider"></span>

                    </label>

                </div>

            </div>

        </div>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        8. Maintenance
                    </h2>

                    <p>
                        Manage maintenance mode and system maintenance actions.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Maintenance Mode
                        </div>

                        <div class="setting-value">
                            Temporarily restrict access during maintenance
                        </div>

                    </div>


                    <label class="switch">

                        <input
                            type="hidden"
                            name="maintenance_mode"
                            value="0"
                        >

                        <input
                            type="checkbox"
                            name="maintenance_mode"
                            value="1"
                            <?php echo e($maintenanceMode == '1' ? 'checked' : ''); ?>

                        >

                        <span class="slider"></span>

                    </label>

                </div>


                

                <button
                    type="button"
                    onclick="openSetting('maintenance_message')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Maintenance Message
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="maintenance_message"
                        >
                            <?php echo e($maintenanceMessage); ?>

                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            System Status
                        </div>

                        <div class="status-inline">

                            <span class="status-dot"></span>

                            <span>
                                System Operational
                            </span>

                        </div>

                    </div>

                </div>


                

                <div class="action-grid">

                    <button
                        type="submit"
                        formaction="<?php echo e(route('admin.settings.cache.clear')); ?>"
                        formmethod="POST"
                        onclick="return confirm('Are you sure you want to clear the system cache?');"
                        class="action-button"
                    >
                        Clear System Cache
                    </button>


                    <button
                        type="submit"
                        formaction="<?php echo e(route('admin.settings.queue.reset')); ?>"
                        formmethod="POST"
                        onclick="return confirm('WARNING: This will remove all queue tickets created today. Continue?');"
                        class="action-button warning"
                    >
                        Reset Daily Queue
                    </button>

                </div>

            </div>

        </div>


        
        
        

        <div class="settings-card">

            <div class="card-header">

                <div>

                    <h2>
                        9. Data & Backup
                    </h2>

                    <p>
                        Monitor database status and manage system backups.
                    </p>

                </div>

            </div>


            <div class="settings-content">

                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Database Status
                        </div>

                        <div class="setting-value">
                            MySQL database connection
                        </div>

                    </div>


                    <?php if($databaseConnected ?? false): ?>

                        <span class="status-badge">

                            <span class="status-dot"></span>

                            Connected

                        </span>

                    <?php else: ?>

                        <span class="status-badge error">

                            <span class="status-dot"></span>

                            Disconnected

                        </span>

                    <?php endif; ?>

                </div>


                

                <div class="setting-row">

                    <div>

                        <div class="setting-title">
                            Last Backup
                        </div>

                        <div class="setting-value">
                            Database backup information
                        </div>

                    </div>


                    <span class="backup-value">

                        <?php echo e(!empty($settings['last_backup'])
                            ? $settings['last_backup']
                            : 'No backup recorded'); ?>


                    </span>

                </div>


                

                <button
                    type="button"
                    onclick="openSetting('data_retention_period')"
                    class="setting-button"
                >

                    <div>

                        <div class="setting-title">
                            Data Retention Period
                        </div>

                        <div
                            class="setting-value"
                            data-setting-value="data_retention_period"
                        >
                            <?php echo e($dataRetentionPeriod); ?> days
                        </div>

                    </div>

                    <span class="setting-arrow">
                        ›
                    </span>

                </button>


                

                <div class="action-grid three">

                    <button
                        type="submit"
                        formaction="<?php echo e(route('admin.settings.backup')); ?>"
                        formmethod="POST"
                        onclick="return confirm('Create a new database backup now?');"
                        class="action-button primary"
                    >
                        Create Database Backup
                    </button>


                    <a
                        href="<?php echo e(route('admin.settings.backup.download')); ?>"
                        class="action-button"
                    >
                        Download Backup
                    </a>


                    <button
                        type="submit"
                        formaction="<?php echo e(route('admin.settings.queue.clear-old')); ?>"
                        formmethod="POST"
                        onclick="return confirm('This will permanently delete queue records older than the configured retention period. Continue?');"
                        class="action-button danger"
                    >
                        Clear Old Queue Records
                    </button>

                </div>

            </div>

        </div>


        
        
        

        <input
            type="hidden"
            name="site_name"
            id="site_name"
            value="<?php echo e($siteName); ?>"
        >

        <input
            type="hidden"
            name="college_name"
            id="college_name"
            value="<?php echo e($collegeName); ?>"
        >

        <input
            type="hidden"
            name="system_description"
            id="system_description"
            value="<?php echo e($systemDescription); ?>"
        >

        <input
            type="hidden"
            name="session_timeout"
            id="session_timeout"
            value="<?php echo e($sessionTimeout); ?>"
        >

        <input
            type="hidden"
            name="max_login_attempts"
            id="max_login_attempts"
            value="<?php echo e($maxLoginAttempts); ?>"
        >

        <input
            type="hidden"
            name="lockout_duration"
            id="lockout_duration"
            value="<?php echo e($lockoutDuration); ?>"
        >

        <input
            type="hidden"
            name="minimum_password_length"
            id="minimum_password_length"
            value="<?php echo e($minimumPasswordLength); ?>"
        >

        <input
            type="hidden"
            name="primary_color"
            id="primary_color"
            value="<?php echo e($primaryColor); ?>"
        >

        <input
            type="hidden"
            name="secondary_color"
            id="secondary_color"
            value="<?php echo e($secondaryColor); ?>"
        >

        <input
            type="hidden"
            name="accent_color"
            id="accent_color"
            value="<?php echo e($accentColor); ?>"
        >

        <input
            type="hidden"
            name="maintenance_message"
            id="maintenance_message"
            value="<?php echo e($maintenanceMessage); ?>"
        >

        <input
            type="hidden"
            name="data_retention_period"
            id="data_retention_period"
            value="<?php echo e($dataRetentionPeriod); ?>"
        >


        
        
        

        <input
            type="hidden"
            name="daily_reset_time"
            id="daily_reset_time"
            value="<?php echo e($dailyResetTime); ?>"
        >

        <input
            type="hidden"
            name="default_waiting_time"
            id="default_waiting_time"
            value="<?php echo e($defaultWaitingTime); ?>"
        >

        <input
            type="hidden"
            name="max_active_tickets_per_student"
            id="max_active_tickets_per_student"
            value="<?php echo e($maxActiveTicketsPerStudent); ?>"
        >

        <input
            type="hidden"
            name="queue_starting_value"
            id="queue_starting_value"
            value="<?php echo e($queueStartingValue); ?>"
        >

        <input
            type="hidden"
            name="default_opening_time"
            id="default_opening_time"
            value="<?php echo e($defaultOpeningTime); ?>"
        >

        <input
            type="hidden"
            name="default_closing_time"
            id="default_closing_time"
            value="<?php echo e($defaultClosingTime); ?>"
        >

        <input
            type="hidden"
            name="default_lunch_break"
            id="default_lunch_break"
            value="<?php echo e($defaultLunchBreak); ?>"
        >

    </form>

</div>






<div
    id="settingModal"
    class="modal-overlay hidden"
>

    <div class="modal-box">

        <div class="modal-header">

            <div>

                <h3 id="modalTitle">
                    Edit Setting
                </h3>

                <p id="modalDescription">
                    Update this setting.
                </p>

            </div>


            <button
                type="button"
                onclick="closeSetting()"
                class="modal-close"
            >
                &times;
            </button>

        </div>


        <div class="modal-body">

            <input
                type="text"
                id="modalInput"
                class="modal-input"
                autocomplete="off"
            >

            <p
                id="modalHint"
                class="modal-hint"
            ></p>

        </div>


        <div class="modal-footer">

            <button
                type="button"
                onclick="closeSetting()"
                class="modal-cancel"
            >
                Cancel
            </button>


            <button
                type="button"
                onclick="saveSetting()"
                class="modal-apply"
            >
                Apply
            </button>

        </div>

    </div>

</div>






<style>

    /* =========================================================
       ADMIN HEADER
    ========================================================= */

    .admin-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 20px;
        padding: 22px 24px;
        border-radius: 18px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow:
            8px 8px 18px rgba(15, 23, 42, 0.08),
            -5px -5px 14px rgba(255, 255, 255, 0.9);
    }

    .admin-header-label {
        font-size: 10px;
        font-weight: 800;
        letter-spacing: 0.16em;
        color: #64748b;
    }

    .admin-header-title {
        margin-top: 3px;
        font-size: 24px;
        line-height: 1.2;
        font-weight: 800;
        color: #0f172a;
    }

    .admin-header-description {
        margin-top: 5px;
        font-size: 12px;
        color: #64748b;
    }

    .system-status {
        display: flex;
        align-items: center;
        gap: 7px;
        padding: 8px 12px;
        border-radius: 999px;
        background: #ecfdf5;
        border: 1px solid #d1fae5;
        color: #059669;
        font-size: 11px;
        font-weight: 700;
        white-space: nowrap;
    }


    /* =========================================================
       SETTINGS CARD
    ========================================================= */

    .settings-card {
        margin-bottom: 18px;
        overflow: hidden;
        border-radius: 16px;
        background: #ffffff;
        border: 1px solid #e2e8f0;
        box-shadow:
            5px 5px 14px rgba(15, 23, 42, 0.06),
            -4px -4px 12px rgba(255, 255, 255, 0.9);
    }

    .card-header {
        padding: 16px 18px;
        border-bottom: 1px solid #f1f5f9;
    }

    .card-header h2 {
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
    }

    .card-header p {
        margin-top: 3px;
        font-size: 11px;
        color: #94a3b8;
    }

    .settings-content {
        padding: 12px;
        display: flex;
        flex-direction: column;
        gap: 8px;
    }


    /* =========================================================
       SETTINGS ROW
    ========================================================= */

    .setting-button,
    .setting-row {
        width: 100%;
        min-height: 58px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        padding: 10px 13px;
        border: 1px solid #e2e8f0;
        border-radius: 11px;
        background: #ffffff;
        text-align: left;
        transition: all 0.15s ease;
    }

    .setting-button:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        transform: translateY(-1px);
    }

    .setting-title {
        font-size: 12px;
        font-weight: 700;
        color: #334155;
    }

    .setting-value {
        margin-top: 3px;
        font-size: 11px;
        color: #94a3b8;
        max-width: 600px;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .setting-arrow {
        flex-shrink: 0;
        font-size: 23px;
        line-height: 1;
        color: #94a3b8;
    }


    /* =========================================================
       COLOR
    ========================================================= */

    .color-value {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-top: 3px;
    }

    .color-value .setting-value {
        margin-top: 0;
    }

    .color-preview {
        width: 16px;
        height: 16px;
        flex-shrink: 0;
        border-radius: 5px;
        border: 1px solid #cbd5e1;
    }


    /* =========================================================
       SWITCH
    ========================================================= */

    .switch {
        position: relative;
        display: inline-flex;
        width: 40px;
        height: 22px;
        flex-shrink: 0;
    }

    .switch input {
        position: absolute;
        opacity: 0;
        width: 0;
        height: 0;
    }

    .slider {
        position: absolute;
        inset: 0;
        cursor: pointer;
        background: #cbd5e1;
        border-radius: 999px;
        transition: 0.2s;
    }

    .slider::before {
        content: "";
        position: absolute;
        width: 18px;
        height: 18px;
        left: 2px;
        top: 2px;
        background: #ffffff;
        border-radius: 50%;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.15);
        transition: 0.2s;
    }

    .switch input:checked + .slider {
        background: #2563eb;
    }

    .switch input:checked + .slider::before {
        transform: translateX(18px);
    }


    /* =========================================================
       STATUS
    ========================================================= */

    .status-dot {
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #10b981;
        flex-shrink: 0;
    }

    .status-inline {
        display: flex;
        align-items: center;
        gap: 7px;
        margin-top: 5px;
        color: #059669;
        font-size: 11px;
        font-weight: 700;
    }

    .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 9px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #059669;
        font-size: 10px;
        font-weight: 700;
        white-space: nowrap;
    }

    .status-badge.error {
        background: #fef2f2;
        color: #dc2626;
    }

    .status-badge.error .status-dot {
        background: #ef4444;
    }


    /* =========================================================
       ACTION BUTTONS
    ========================================================= */

    .action-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        padding-top: 4px;
    }

    .action-grid.three {
        grid-template-columns: repeat(3, minmax(0, 1fr));
    }

    .action-button {
        min-height: 40px;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 8px 12px;
        border: 1px solid #e2e8f0;
        border-radius: 9px;
        background: #ffffff;
        color: #475569;
        font-size: 11px;
        font-weight: 700;
        text-align: center;
        cursor: pointer;
        transition: all 0.15s ease;
    }

    .action-button:hover {
        background: #f8fafc;
        border-color: #cbd5e1;
        transform: translateY(-1px);
    }

    .action-button.primary {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
    }

    .action-button.primary:hover {
        background: #1d4ed8;
    }

    .action-button.warning {
        background: #fffbeb;
        border-color: #fde68a;
        color: #b45309;
    }

    .action-button.warning:hover {
        background: #fef3c7;
    }

    .action-button.danger {
        background: #fef2f2;
        border-color: #fecaca;
        color: #dc2626;
    }

    .action-button.danger:hover {
        background: #fee2e2;
    }


    /* =========================================================
       BACKUP
    ========================================================= */

    .backup-value {
        max-width: 280px;
        font-size: 11px;
        color: #64748b;
        text-align: right;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }


    /* =========================================================
       MESSAGES
    ========================================================= */

    .message {
        margin-bottom: 12px;
        padding: 10px 12px;
        border-radius: 10px;
        font-size: 11px;
    }

    .success-message {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #047857;
    }

    .error-message {
        background: #fef2f2;
        border: 1px solid #fecaca;
        color: #b91c1c;
    }


    /* =========================================================
       MODAL
    ========================================================= */

    .modal-overlay {
        position: fixed;
        inset: 0;
        z-index: 50;
        align-items: center;
        justify-content: center;
        padding: 16px;
        background: rgba(15, 23, 42, 0.45);
        backdrop-filter: blur(4px);
    }

    .modal-overlay.hidden {
        display: none;
    }

    .modal-overlay:not(.hidden) {
        display: flex;
    }

    .modal-box {
        width: 100%;
        max-width: 430px;
        overflow: hidden;
        border-radius: 16px;
        background: #ffffff;
        box-shadow:
            10px 10px 30px rgba(15, 23, 42, 0.18),
            -5px -5px 15px rgba(255, 255, 255, 0.9);
    }

    .modal-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 15px;
        padding: 16px 18px;
        border-bottom: 1px solid #f1f5f9;
    }

    .modal-header h3 {
        font-size: 14px;
        font-weight: 800;
        color: #0f172a;
    }

    .modal-header p {
        margin-top: 3px;
        font-size: 10px;
        color: #94a3b8;
    }

    .modal-close {
        font-size: 22px;
        line-height: 1;
        color: #94a3b8;
        background: transparent;
        border: 0;
        cursor: pointer;
    }

    .modal-close:hover {
        color: #334155;
    }

    .modal-body {
        padding: 18px;
    }

    .modal-input {
        width: 100%;
        padding: 10px 11px;
        border: 1px solid #cbd5e1;
        border-radius: 9px;
        outline: none;
        font-size: 12px;
        color: #0f172a;
        background: #ffffff;
    }

    .modal-input:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
    }

    .modal-hint {
        margin-top: 7px;
        font-size: 10px;
        color: #94a3b8;
    }

    .modal-footer {
        display: flex;
        justify-content: flex-end;
        gap: 8px;
        padding: 12px 18px;
        border-top: 1px solid #f1f5f9;
    }

    .modal-cancel,
    .modal-apply {
        min-height: 34px;
        padding: 7px 13px;
        border-radius: 8px;
        font-size: 11px;
        font-weight: 700;
        cursor: pointer;
    }

    .modal-cancel {
        background: #ffffff;
        border: 1px solid #e2e8f0;
        color: #475569;
    }

    .modal-apply {
        background: #2563eb;
        border: 1px solid #2563eb;
        color: #ffffff;
    }


    /* =========================================================
       DARK MODE
    ========================================================= */

    html.dark {
        background: #0f172a !important;
        color: #f8fafc;
    }

    html.dark body {
        background: #0f172a !important;
        color: #f8fafc;
    }

    html.dark .admin-header,
    html.dark .settings-card,
    html.dark .setting-button,
    html.dark .setting-row,
    html.dark .modal-box {
        background: #1e293b;
        border-color: #334155;
        color: #f8fafc;
    }

    html.dark .admin-header {
        box-shadow: 7px 7px 18px rgba(0, 0, 0, 0.25);
    }

    html.dark .admin-header-title,
    html.dark .card-header h2,
    html.dark .setting-title,
    html.dark .modal-header h3 {
        color: #f8fafc;
    }

    html.dark .admin-header-label,
    html.dark .admin-header-description,
    html.dark .card-header p,
    html.dark .setting-value,
    html.dark .modal-header p,
    html.dark .modal-hint {
        color: #94a3b8;
    }

    html.dark .card-header,
    html.dark .modal-header,
    html.dark .modal-footer {
        border-color: #334155;
    }

    html.dark .setting-button:hover {
        background: #273449;
        border-color: #475569;
    }

    html.dark .setting-arrow {
        color: #64748b;
    }

    html.dark .modal-input {
        background: #0f172a;
        border-color: #475569;
        color: #f8fafc;
    }

    html.dark .modal-cancel,
    html.dark .action-button {
        background: #1e293b;
        border-color: #475569;
        color: #e2e8f0;
    }

    html.dark .action-button:hover,
    html.dark .modal-cancel:hover {
        background: #334155;
    }

    html.dark .color-preview {
        border-color: #475569;
    }


    /* =========================================================
       MOBILE
    ========================================================= */

    @media (max-width: 640px) {

        .admin-header {
            padding: 18px;
            align-items: flex-start;
        }

        .admin-header-title {
            font-size: 20px;
        }

        .system-status {
            display: none;
        }

        .setting-button,
        .setting-row {
            min-height: 54px;
            padding: 9px 10px;
            gap: 10px;
        }

        .setting-value {
            max-width: 220px;
        }

        .action-grid,
        .action-grid.three {
            grid-template-columns: 1fr;
        }

        .backup-value {
            max-width: 140px;
        }

    }

</style>






<script>

    let currentSetting = null;
    let autoSaveTimer = null;
    let isSubmitting = false;


    /* =========================================================
       AUTO SAVE
    ========================================================= */

    function autoSaveSettings() {

        const form = document.getElementById('settingsForm');

        if (!form || isSubmitting) {
            return;
        }

        clearTimeout(autoSaveTimer);

        autoSaveTimer = setTimeout(function () {

            if (isSubmitting) {
                return;
            }

            isSubmitting = true;

            form.submit();

        }, 400);

    }


    /* =========================================================
       SETTING NAMES
    ========================================================= */

    const settingNames = {

        site_name:
            'System Name',

        college_name:
            'College / Organization Name',

        system_description:
            'System Description',

        daily_reset_time:
            'Daily Queue Reset Time',

        default_waiting_time:
            'Default Waiting Time',

        max_active_tickets_per_student:
            'Maximum Active Tickets Per Student',

        queue_starting_value:
            'Queue Starting Value',

        default_opening_time:
            'Default Opening Time',

        default_closing_time:
            'Default Closing Time',

        default_lunch_break:
            'Default Lunch Break',

        session_timeout:
            'Session Timeout',

        max_login_attempts:
            'Maximum Login Attempts',

        lockout_duration:
            'Account Lockout Duration',

        minimum_password_length:
            'Minimum Password Length',

        primary_color:
            'Primary Color',

        secondary_color:
            'Secondary Color',

        accent_color:
            'Accent Color',

        maintenance_message:
            'Maintenance Message',

        data_retention_period:
            'Data Retention Period'

    };


    /* =========================================================
       SETTING HINTS
    ========================================================= */

    const settingHints = {

        daily_reset_time:
            'Enter the time when the daily queue should reset.',

        default_waiting_time:
            'Enter the default waiting time in minutes.',

        max_active_tickets_per_student:
            'Enter the maximum number of active tickets a student may have.',

        queue_starting_value:
            'Enter the first queue number used when the daily queue starts.',

        default_opening_time:
            'Enter the default queue opening time.',

        default_closing_time:
            'Enter the default queue closing time.',

        default_lunch_break:
            'Enter the default lunch break time.',

        session_timeout:
            'Enter the number of minutes before an inactive session expires.',

        max_login_attempts:
            'Enter the maximum number of failed login attempts.',

        lockout_duration:
            'Enter the number of minutes an account remains locked.',

        minimum_password_length:
            'Enter the minimum number of characters.',

        primary_color:
            'Use HEX format, for example #073B91.',

        secondary_color:
            'Use HEX format, for example #1264D8.',

        accent_color:
            'Use HEX format, for example #FFB300.',

        data_retention_period:
            'Enter the number of days queue records should be retained.'

    };


    /* =========================================================
       OPEN MODAL
    ========================================================= */

    function openSetting(setting) {

        currentSetting = setting;

        const input =
            document.getElementById(setting);

        if (!input) {
            return;
        }

        const modal =
            document.getElementById('settingModal');

        const modalInput =
            document.getElementById('modalInput');

        const modalTitle =
            document.getElementById('modalTitle');

        const modalHint =
            document.getElementById('modalHint');


        modalTitle.textContent =
            settingNames[setting] || 'Edit Setting';


        modalInput.value =
            input.value;


        modalHint.textContent =
            settingHints[setting] || '';


        if (
            setting === 'primary_color' ||
            setting === 'secondary_color' ||
            setting === 'accent_color'
        ) {

            modalInput.type = 'text';

        }

        else if (
            setting === 'session_timeout' ||
            setting === 'max_login_attempts' ||
            setting === 'lockout_duration' ||
            setting === 'minimum_password_length' ||
            setting === 'default_waiting_time' ||
            setting === 'max_active_tickets_per_student' ||
            setting === 'queue_starting_value' ||
            setting === 'data_retention_period'
        ) {

            modalInput.type = 'number';

        }

        else if (
            setting === 'daily_reset_time' ||
            setting === 'default_opening_time' ||
            setting === 'default_closing_time'
        ) {

            modalInput.type = 'time';

        }

        else {

            modalInput.type = 'text';

        }


        modal.classList.remove('hidden');


        setTimeout(function () {

            modalInput.focus();

            modalInput.select();

        }, 50);

    }


    /* =========================================================
       CLOSE MODAL
    ========================================================= */

    function closeSetting() {

        const modal =
            document.getElementById('settingModal');

        if (!modal) {
            return;
        }

        modal.classList.add('hidden');

        currentSetting = null;

    }


    /* =========================================================
       SAVE MODAL SETTING
    ========================================================= */

    function saveSetting() {

        if (!currentSetting) {
            return;
        }


        const input =
            document.getElementById(currentSetting);

        const modalInput =
            document.getElementById('modalInput');


        if (!input || !modalInput) {
            return;
        }


        const value =
            modalInput.value.trim();


        if (value === '') {

            alert('Please enter a value.');

            return;

        }


        input.value = value;


        const display =
            document.querySelector(
                '[data-setting-value="' +
                currentSetting +
                '"]'
            );


        if (display) {

            let displayValue = value;


            if (currentSetting === 'session_timeout') {

                displayValue =
                    value + ' minutes';

            }


            if (currentSetting === 'default_waiting_time') {

                displayValue =
                    value + ' minutes';

            }


            if (currentSetting === 'max_active_tickets_per_student') {

                displayValue =
                    value + ' ticket(s)';

            }


            if (currentSetting === 'max_login_attempts') {

                displayValue =
                    value + ' attempts';

            }


            if (currentSetting === 'lockout_duration') {

                displayValue =
                    value + ' minutes';

            }


            if (currentSetting === 'minimum_password_length') {

                displayValue =
                    value + ' characters';

            }


            if (currentSetting === 'data_retention_period') {

                displayValue =
                    value + ' days';

            }


            display.textContent =
                displayValue;

        }


        /* COLOR PREVIEWS */

        if (currentSetting === 'primary_color') {

            const preview =
                document.getElementById(
                    'primaryColorPreview'
                );

            if (preview) {

                preview.style.backgroundColor =
                    value;

            }

        }


        if (currentSetting === 'secondary_color') {

            const preview =
                document.getElementById(
                    'secondaryColorPreview'
                );

            if (preview) {

                preview.style.backgroundColor =
                    value;

            }

        }


        if (currentSetting === 'accent_color') {

            const preview =
                document.getElementById(
                    'accentColorPreview'
                );

            if (preview) {

                preview.style.backgroundColor =
                    value;

            }

        }


        closeSetting();

        autoSaveSettings();

    }


    /* =========================================================
       NORMAL SETTINGS AUTO SAVE
    ========================================================= */

    const settingsForm =
        document.getElementById('settingsForm');


    if (settingsForm) {

        settingsForm.addEventListener(
            'change',
            function(event) {

                /*
                |--------------------------------------------------------------------------
                | Dark Mode is handled separately.
                |--------------------------------------------------------------------------
                */

                if (
                    event.target &&
                    event.target.id === 'darkModeToggle'
                ) {

                    return;

                }


                autoSaveSettings();

            }
        );

    }


    /* =========================================================
       DARK MODE
    ========================================================= */

    const darkModeToggle =
        document.getElementById('darkModeToggle');

    const darkModeValue =
        document.getElementById('darkModeValue');


    /* =========================================================
       APPLY DARK MODE
    ========================================================= */

    function applyDarkMode(enabled) {

        document.documentElement.classList.toggle(
            'dark',
            enabled
        );


        if (darkModeValue) {

            darkModeValue.value =
                enabled ? '1' : '0';

        }

    }


    /* =========================================================
       DARK MODE SAVE
    ========================================================= */

    function saveDarkMode() {

        const form =
            document.getElementById('settingsForm');


        if (
            !form ||
            !darkModeToggle ||
            !darkModeValue
        ) {

            return;

        }


        /*
        |--------------------------------------------------------------------------
        | Make sure Laravel receives the correct dark_mode value.
        |--------------------------------------------------------------------------
        */

        darkModeValue.value =
            darkModeToggle.checked ? '1' : '0';


        /*
        |--------------------------------------------------------------------------
        | Cancel any previous automatic submission.
        |--------------------------------------------------------------------------
        */

        clearTimeout(autoSaveTimer);


        /*
        |--------------------------------------------------------------------------
        | Prevent duplicate submissions.
        |--------------------------------------------------------------------------
        */

        if (isSubmitting) {
            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Submit the complete settings form.
        |--------------------------------------------------------------------------
        | The required queue fields are now included, so Laravel validation
        | will no longer reject the dark mode request because those fields
        | are missing.
        |--------------------------------------------------------------------------
        */

        setTimeout(function() {

            if (isSubmitting) {
                return;
            }

            isSubmitting = true;

            form.submit();

        }, 150);

    }


    /* =========================================================
       INITIALIZE DARK MODE
    ========================================================= */

    function initializeDarkMode() {

        if (
            !darkModeToggle ||
            !darkModeValue
        ) {

            return;

        }


        /*
        |--------------------------------------------------------------------------
        | Laravel is the source of truth.
        |--------------------------------------------------------------------------
        */

        const enabled =
            darkModeValue.value === '1';


        darkModeToggle.checked =
            enabled;


        applyDarkMode(
            enabled
        );

    }


    /* =========================================================
       DARK MODE SWITCH
    ========================================================= */

    if (darkModeToggle) {

        darkModeToggle.addEventListener(
            'change',
            function() {

                const enabled =
                    this.checked;


                /*
                |--------------------------------------------------------------------------
                | Change the interface immediately.
                |--------------------------------------------------------------------------
                */

                applyDarkMode(
                    enabled
                );


                /*
                |--------------------------------------------------------------------------
                | Save it to Laravel.
                |--------------------------------------------------------------------------
                */

                saveDarkMode();

            }
        );

    }


    /* =========================================================
       INITIALIZE
    ========================================================= */

    initializeDarkMode();


    /* =========================================================
       MODAL BACKDROP
    ========================================================= */

    const settingModal =
        document.getElementById('settingModal');


    if (settingModal) {

        settingModal.addEventListener(
            'click',
            function(event) {

                if (event.target === this) {

                    closeSetting();

                }

            }
        );

    }


    /* =========================================================
       ESCAPE KEY
    ========================================================= */

    document.addEventListener(
        'keydown',
        function(event) {

            if (event.key === 'Escape') {

                closeSetting();

            }

        }
    );

</script>

<?php $__env->stopSection(); ?>
<?php echo $__env->make('layouts.admin', \Illuminate\Support\Arr::except(get_defined_vars(), ['__data', '__path']))->render(); ?><?php /**PATH C:\QUEUEFLOW TMC\QueueFlow_TMC\resources\views/settings/index.blade.php ENDPATH**/ ?>