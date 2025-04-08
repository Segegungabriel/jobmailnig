<?php
$permissions = [
    'super_admin' => [
        'canPostJobs' => true,
        'canEditJobs' => true,
        'canDeleteJobs' => true,
        'canManageAdmins' => true,
        'canGenerateRSS' => true,
        'canViewStats' => true,
        'canChangePassword' => true,
        'canViewActivityLog' => true,
        'canManageSettings' => true,
        'canManagePermissions' => true,
        'canManageBlog' => true
    ],
    'editor' => [
        'canPostJobs' => true,
        'canEditJobs' => true,
        'canDeleteJobs' => false,
        'canManageAdmins' => false,
        'canGenerateRSS' => false,
        'canViewStats' => true,
        'canChangePassword' => true,
        'canViewActivityLog' => false,
        'canManageSettings' => false,
        'canManagePermissions' => false,
        'canManageBlog' => true
    ],
    'moderator' => [
        'canPostJobs' => false,
        'canEditJobs' => true,
        'canDeleteJobs' => false,
        'canManageAdmins' => false,
        'canGenerateRSS' => false,
        'canViewStats' => true,
        'canChangePassword' => true,
        'canViewActivityLog' => true,
        'canManageSettings' => false,
        'canManagePermissions' => false,
        'canManageBlog' => false
    ],
    'viewer' => [
        'canPostJobs' => false,
        'canEditJobs' => false,
        'canDeleteJobs' => false,
        'canManageAdmins' => false,
        'canGenerateRSS' => false,
        'canViewStats' => false,
        'canChangePassword' => true,
        'canViewActivityLog' => false,
        'canManageSettings' => false,
        'canManagePermissions' => false,
        'canManageBlog' => false
    ]
];
?>