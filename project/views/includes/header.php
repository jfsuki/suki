<?php 
// Cargamos el array del menú
$frameworkRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__, 3) . '/framework';
$menuItems = include $frameworkRoot . '/config/menu.php'; 

function normalize_menu_url(string $url): string
{
    if ($url === '' || $url === '#') {
        return $url;
    }
    if (preg_match('/^(https?:)?\\/\\//', $url)) {
        return $url;
    }
    return $url[0] === '/' ? $url : '/' . $url;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Suki ERP</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>[x-cloak] { display: none !important; }</style>
</head>
<body class="bg-gray-50" x-data="{ mobileMenuOpen: false }">

    <nav class="bg-indigo-600 text-white shadow-lg relative z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center h-16">
                
                <div class="flex-shrink-0 font-bold text-2xl tracking-tighter">
                    SUKI <span class="font-light text-indigo-200">ERP</span>
                </div>

                <div class="hidden md:flex items-center space-x-4">
                    <?php foreach ($menuItems as $item): ?>
                        <?php if (isset($item['submenu'])): ?>
                            <div class="relative" x-data="{ open: false }" @click.away="open = false">
                                <button @click="open = !open" class="flex items-center px-3 py-2 rounded-md text-sm font-medium hover:bg-indigo-500 transition">
                                    <span><?= $item['label'] ?></span>
                                    <svg class="ml-1 w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <div x-show="open" x-cloak 
                                     class="absolute left-0 mt-2 w-48 bg-white rounded-md shadow-xl py-2 text-gray-800 ring-1 ring-black ring-opacity-5">
                                    <?php foreach ($item['submenu'] as $sub): ?>
                                        <a href="<?= normalize_menu_url($sub['url']) ?>" class="block px-4 py-2 text-sm hover:bg-indigo-50 hover:text-indigo-600">
                                            <?= $sub['label'] ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="<?= normalize_menu_url($item['url']) ?>" class="px-3 py-2 rounded-md text-sm font-medium hover:bg-indigo-500 transition">
                                <?= $item['label'] ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="md:hidden flex items-center">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="inline-flex items-center justify-center p-2 rounded-md hover:bg-indigo-500 focus:outline-none">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div x-show="mobileMenuOpen" x-cloak class="md:hidden bg-indigo-700 border-t border-indigo-500 transition-all">
            <div class="px-2 pt-2 pb-3 space-y-1">
                <?php foreach ($menuItems as $item): ?>
                    <?php if (isset($item['submenu'])): ?>
                        <div x-data="{ open: false }">
                            <button @click="open = !open" class="flex justify-between items-center w-full px-3 py-2 rounded-md text-base font-medium hover:bg-indigo-800">
                                <span><?= $item['label'] ?></span>
                                <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div x-show="open" class="pl-4 bg-indigo-800/50 rounded-md mt-1">
                                <?php foreach ($item['submenu'] as $sub): ?>
                                    <a href="<?= normalize_menu_url($sub['url']) ?>" class="block px-3 py-2 text-sm text-indigo-100 hover:text-white">
                                        <?= $sub['label'] ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= normalize_menu_url($item['url']) ?>" class="block px-3 py-2 rounded-md text-base font-medium hover:bg-indigo-800">
                            <?= $item['label'] ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <main class="container mx-auto mt-8 px-4">
