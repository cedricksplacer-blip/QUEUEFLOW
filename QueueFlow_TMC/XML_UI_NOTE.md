# XML UI note for QueueFlow TMC

This ZIP is a Laravel web application. Its interface is rendered with Blade/HTML/CSS, not Android XML.

The office structure has been updated to:
1. Registrar
2. Cashier
3. Admissions
4. SASO
5. Scholarship Office
6. School Clinic

If “XML UI” means **Android XML layouts**, those layouts should be created in the separate Flutter/Android client rather than replacing Laravel Blade files. The Laravel web UI cannot be converted to Android XML without creating a separate Android client.
