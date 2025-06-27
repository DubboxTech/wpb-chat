<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Create permissions
        $permissions = [
            // Dashboard permissions
            ['name' => 'dashboard.view', 'display_name' => 'Ver Dashboard', 'group' => 'dashboard'],
            ['name' => 'dashboard.analytics', 'display_name' => 'Ver Analytics', 'group' => 'dashboard'],
            
            // User management permissions
            ['name' => 'users.view', 'display_name' => 'Ver Usuários', 'group' => 'users'],
            ['name' => 'users.create', 'display_name' => 'Criar Usuários', 'group' => 'users'],
            ['name' => 'users.edit', 'display_name' => 'Editar Usuários', 'group' => 'users'],
            ['name' => 'users.delete', 'display_name' => 'Excluir Usuários', 'group' => 'users'],
            
            // Role management permissions
            ['name' => 'roles.view', 'display_name' => 'Ver Cargos', 'group' => 'roles'],
            ['name' => 'roles.create', 'display_name' => 'Criar Cargos', 'group' => 'roles'],
            ['name' => 'roles.edit', 'display_name' => 'Editar Cargos', 'group' => 'roles'],
            ['name' => 'roles.delete', 'display_name' => 'Excluir Cargos', 'group' => 'roles'],
            
            // WhatsApp permissions
            ['name' => 'whatsapp.view', 'display_name' => 'Ver Chats', 'group' => 'whatsapp'],
            ['name' => 'whatsapp.send', 'display_name' => 'Enviar Mensagens', 'group' => 'whatsapp'],
            ['name' => 'whatsapp.templates', 'display_name' => 'Gerenciar Templates', 'group' => 'whatsapp'],
            ['name' => 'whatsapp.config', 'display_name' => 'Configurar WhatsApp', 'group' => 'whatsapp'],
            
            // Campaign permissions
            ['name' => 'campaigns.view', 'display_name' => 'Ver Campanhas', 'group' => 'campaigns'],
            ['name' => 'campaigns.create', 'display_name' => 'Criar Campanhas', 'group' => 'campaigns'],
            ['name' => 'campaigns.edit', 'display_name' => 'Editar Campanhas', 'group' => 'campaigns'],
            ['name' => 'campaigns.delete', 'display_name' => 'Excluir Campanhas', 'group' => 'campaigns'],
            ['name' => 'campaigns.send', 'display_name' => 'Enviar Campanhas', 'group' => 'campaigns'],
            
            // Contact permissions
            ['name' => 'contacts.view', 'display_name' => 'Ver Contatos', 'group' => 'contacts'],
            ['name' => 'contacts.create', 'display_name' => 'Criar Contatos', 'group' => 'contacts'],
            ['name' => 'contacts.edit', 'display_name' => 'Editar Contatos', 'group' => 'contacts'],
            ['name' => 'contacts.delete', 'display_name' => 'Excluir Contatos', 'group' => 'contacts'],
            ['name' => 'contacts.import', 'display_name' => 'Importar Contatos', 'group' => 'contacts'],
            
            // AI permissions
            ['name' => 'ai.config', 'display_name' => 'Configurar IA', 'group' => 'ai'],
            ['name' => 'ai.training', 'display_name' => 'Treinar IA', 'group' => 'ai'],
            
            // Reports permissions
            ['name' => 'reports.view', 'display_name' => 'Ver Relatórios', 'group' => 'reports'],
            ['name' => 'reports.export', 'display_name' => 'Exportar Relatórios', 'group' => 'reports'],
            
            // System permissions
            ['name' => 'system.config', 'display_name' => 'Configurações do Sistema', 'group' => 'system'],
            ['name' => 'system.logs', 'display_name' => 'Ver Logs', 'group' => 'system'],
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission['name']],
                $permission
            );
        }

        // Create roles
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            [
                'display_name' => 'Administrador',
                'description' => 'Acesso total ao sistema',
                'permissions' => Permission::pluck('name')->toArray(),
            ]
        );

        $marketingRole = Role::firstOrCreate(
            ['name' => 'marketing'],
            [
                'display_name' => 'Marketing',
                'description' => 'Acesso a campanhas e relatórios',
                'permissions' => [
                    'dashboard.view',
                    'dashboard.analytics',
                    'whatsapp.view',
                    'whatsapp.send',
                    'whatsapp.templates',
                    'campaigns.view',
                    'campaigns.create',
                    'campaigns.edit',
                    'campaigns.send',
                    'contacts.view',
                    'contacts.create',
                    'contacts.edit',
                    'contacts.import',
                    'reports.view',
                    'reports.export',
                ],
            ]
        );

        $supportRole = Role::firstOrCreate(
            ['name' => 'support'],
            [
                'display_name' => 'Suporte',
                'description' => 'Acesso a chats e atendimento',
                'permissions' => [
                    'dashboard.view',
                    'whatsapp.view',
                    'whatsapp.send',
                    'contacts.view',
                    'contacts.create',
                    'contacts.edit',
                    'reports.view',
                ],
            ]
        );

        // Create default admin user
        $adminUser = User::firstOrCreate(
            ['email' => 'admin@whatsappbusiness.com'],
            [
                'name' => 'Administrador',
                'password' => Hash::make('admin123'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        // Assign admin role to admin user
        if (!$adminUser->hasRole('admin')) {
            $adminUser->assignRole('admin');
        }

        // Create marketing user
        $marketingUser = User::firstOrCreate(
            ['email' => 'marketing@whatsappbusiness.com'],
            [
                'name' => 'Marketing',
                'password' => Hash::make('marketing123'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        if (!$marketingUser->hasRole('marketing')) {
            $marketingUser->assignRole('marketing');
        }

        // Create support user
        $supportUser = User::firstOrCreate(
            ['email' => 'support@whatsappbusiness.com'],
            [
                'name' => 'Suporte',
                'password' => Hash::make('support123'),
                'email_verified_at' => now(),
                'is_active' => true,
            ]
        );

        if (!$supportUser->hasRole('support')) {
            $supportUser->assignRole('support');
        }
    }
}

