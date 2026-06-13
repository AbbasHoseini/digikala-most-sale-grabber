docker compose up -d




# Clean reset (important)
Because WordPress was already broken, do this once:

<!-- docker compose down -v -->
docker compose down 
docker compose up -d
-----------------------------------------
docker exec -it wordpress_app bash


# -----------------------------
docker restart wp_app



راهنمای راه اندازی پلاگین دیجیکالا:

۱. نصب ووکامرس انگلیسی
۲. نصب ووکامرس فارسی
۳. نصب پلاگین
---------------------------------
دیدن لاگ داخل Docker:
docker exec -it wp_app tail -f /var/www/html/wp-content/debug.log




http://localhost:8080/wp-cron.php?doing_wp_cron


: فقط توقف (Stop) کانتینرها
docker compose stop

