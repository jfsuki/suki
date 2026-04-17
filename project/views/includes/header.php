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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] },
                    colors: {
                        brand: { DEFAULT: '#0891B2', light: '#06B6D4', deep: '#0E7490', soft: 'rgba(8,145,178,0.08)' }
                    }
                }
            }
        }
    </script>
    <script defer src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        body { font-family: 'Inter', system-ui, sans-serif; }
    </style>
</head>
<body class="bg-slate-50 font-sans" x-data="{ mobileMenuOpen: false }">

    <nav class="bg-white border-b border-slate-100 shadow-sm relative z-50">
        <div class="container mx-auto px-4">
            <div class="flex justify-between items-center h-16">

                <div class="flex-shrink-0 flex items-center gap-2">
                    <div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,#0891B2,#06B6D4);display:flex;align-items:center;justify-content:center;">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="white"><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"/></svg>
                    </div>
                    <span class="font-bold text-xl tracking-tight text-slate-900">SUKI</span>
                    <span class="text-xs font-semibold text-cyan-600 bg-cyan-50 border border-cyan-200 px-2 py-0.5 rounded-full tracking-wide uppercase">ERP</span>
                </div>

                <div class="hidden md:flex items-center space-x-1">
                    <?php foreach ($menuItems as $item): ?>
                        <?php if (isset($item['submenu'])): ?>
                            <div class="relative" x-data="{ open: false }" @click.away="open = false">
                                <button @click="open = !open" class="flex items-center px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:text-cyan-700 hover:bg-cyan-50 transition-all">
                                    <span><?= $item['label'] ?></span>
                                    <svg class="ml-1 w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                                </button>
                                <div x-show="open" x-cloak
                                     class="absolute left-0 mt-2 w-48 bg-white rounded-xl shadow-lg py-1.5 ring-1 ring-slate-100">
                                    <?php foreach ($item['submenu'] as $sub): ?>
                                        <a href="<?= normalize_menu_url($sub['url']) ?>" class="block px-4 py-2 text-sm text-slate-600 hover:bg-cyan-50 hover:text-cyan-700 transition-colors">
                                            <?= $sub['label'] ?>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php else: ?>
                            <a href="<?= normalize_menu_url($item['url']) ?>" class="px-3 py-2 rounded-lg text-sm font-medium text-slate-600 hover:text-cyan-700 hover:bg-cyan-50 transition-all">
                                <?= $item['label'] ?>
                            </a>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <div class="md:hidden flex items-center">
                    <button @click="mobileMenuOpen = !mobileMenuOpen" class="inline-flex items-center justify-center p-2 rounded-lg text-slate-500 hover:text-cyan-600 hover:bg-cyan-50 focus:outline-none transition-colors">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path x-show="!mobileMenuOpen" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                            <path x-show="mobileMenuOpen" x-cloak stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
            </div>
        </div>

        <div x-show="mobileMenuOpen" x-cloak class="md:hidden bg-white border-t border-slate-100">
            <div class="px-3 pt-2 pb-3 space-y-0.5">
                <?php foreach ($menuItems as $item): ?>
                    <?php if (isset($item['submenu'])): ?>
                        <div x-data="{ open: false }">
                            <button @click="open = !open" class="flex justify-between items-center w-full px-3 py-2.5 rounded-lg text-sm font-medium text-slate-600 hover:bg-cyan-50 hover:text-cyan-700 transition-colors">
                                <span><?= $item['label'] ?></span>
                                <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path></svg>
                            </button>
                            <div x-show="open" class="pl-4 mt-0.5 space-y-0.5">
                                <?php foreach ($item['submenu'] as $sub): ?>
                                    <a href="<?= normalize_menu_url($sub['url']) ?>" class="block px-3 py-2 text-sm text-slate-500 hover:text-cyan-700 hover:bg-cyan-50 rounded-lg transition-colors">
                                        <?= $sub['label'] ?>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <a href="<?= normalize_menu_url($item['url']) ?>" class="block px-3 py-2.5 rounded-lg text-sm font-medium text-slate-600 hover:bg-cyan-50 hover:text-cyan-700 transition-colors">
                            <?= $item['label'] ?>
                        </a>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
        </div>
    </nav>

    <main class="container mx-auto mt-8 px-4">
