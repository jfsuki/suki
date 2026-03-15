# framework/tests/tmp

Carpeta unica para artefactos temporales de testing.

Reglas:
- Solo aqui se guardan resultados temporales (`*_result.json`).
- No usar esta carpeta para fixtures de produccion.
- Esta carpeta puede limpiarse en `reset_test_project.php`.
- `framework/tests/run.php` ejecuta cleanup automatico de artefactos generados antes y despues del suite principal.
- El cleanup automatico usa `APP_ENV` + `TEST_TMP_*`, conserva solo una ventana reciente y no toca `README.md` ni scripts `.php` dejados manualmente.
- El prune post-suite tambien corre si el runner falla para evitar acumulacion tras errores.
