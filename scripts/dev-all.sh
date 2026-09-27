#!/usr/bin/env bash
# اجرای کامل یکاری روی سیستم محلی (مک/لینوکس) با یک دستور:
#
#   bash dev-all.sh /path/to/yekari_apps
#
# پوشهٔ داده‌شده باید design-system, customer, courier, admin, corporate را داشته باشد.
# بک‌اند اگر نباشد در `<apps>/back` کلون می‌شود. همه به آخرین main به‌روز می‌شوند،
# وابستگی‌ها نصب می‌شوند، دیتابیس SQLite محلی با دادهٔ نمونه ساخته می‌شود و
# API + زمان‌بند + چهار اپ هم‌زمان بالا می‌آیند. Ctrl+C همه را می‌بندد.
#
# پیش‌نیاز: git, php >= 8.3 (با pdo_sqlite), composer, node >= 20, npm
set -euo pipefail

APPS="$(cd "${1:-$PWD}" && pwd)"
BACK="$APPS/back"
BACK_REPO="https://github.com/ahmadierfan/yekari_back.git"

say() { printf '\n\033[1;36m» %s\033[0m\n' "$*"; }
die() { printf '\n\033[1;31m✗ %s\033[0m\n' "$*" >&2; exit 1; }

for bin in git php composer node npm; do
  command -v "$bin" >/dev/null || die "$bin نصب نیست (مک: brew install ${bin/composer/composer})"
done
php -r 'exit(version_compare(PHP_VERSION, "8.3", ">=") ? 0 : 1);' || die "PHP 8.3 یا بالاتر لازم است (brew install php)"

for d in design-system customer courier admin corporate; do
  [ -d "$APPS/$d/.git" ] || die "پوشهٔ $APPS/$d پیدا نشد یا ریپوی گیت نیست"
done
[ -d "$BACK/.git" ] || { say "کلون بک‌اند در $BACK"; git clone "$BACK_REPO" "$BACK"; }

say "به‌روزرسانی همه به آخرین main"
for d in design-system customer courier admin corporate back; do
  repo="$APPS/$d"
  if [ -n "$(git -C "$repo" status --porcelain --untracked-files=no)" ]; then
    die "$d تغییر ذخیره‌نشده دارد؛ اول commit یا stash کن"
  fi
  git -C "$repo" fetch -q origin main
  git -C "$repo" checkout -q main
  git -C "$repo" merge -q --ff-only origin/main || die "$d با origin/main هم‌راستا نیست"
  echo "  ✓ $d"
done

say "بک‌اند: نصب و دیتابیس"
cd "$BACK"
composer install -q --no-interaction
if [ ! -f .env ]; then
  cp .env.example .env
  # محلی: SQLite بدون نیاز به MySQL (production همان MySQL می‌ماند)
  perl -pi -e 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/; s/^(DB_(HOST|PORT|DATABASE|USERNAME|PASSWORD)=)/# $1/' .env
  php artisan key:generate -q
fi
touch database/database.sqlite
php artisan migrate --seed --force -q
php artisan storage:link -q 2>/dev/null || true

say "نصب وابستگی‌های اپ‌ها"
for d in design-system customer courier admin corporate; do
  (cd "$APPS/$d" && npm install --silent --no-audit --no-fund) && echo "  ✓ $d"
done

PIDS=()
cleanup() { say "بستن همه"; kill "${PIDS[@]}" 2>/dev/null || true; wait 2>/dev/null || true; }
trap cleanup EXIT INT TERM

LOGS="$APPS/.logs"
mkdir -p "$LOGS"
say "اجرا (لاگ‌ها در $LOGS)"
(cd "$BACK" && php artisan serve --port=8000 >"$LOGS/api.log" 2>&1) & PIDS+=($!)
(cd "$BACK" && php artisan schedule:work >"$LOGS/schedule.log" 2>&1) & PIDS+=($!)
for d in customer courier admin corporate; do
  (cd "$APPS/$d" && NUXT_PUBLIC_DEMO_OTP=12345 npm run dev >"$LOGS/$d.log" 2>&1) & PIDS+=($!)
done

cat <<'EOF'

  API        http://localhost:8000
  مشتری      http://localhost:3500   09121234567
  پیک        http://localhost:3501   09351112233
  مدیریت     http://localhost:3502   09121110000 (عملیات 09122220000، مالی 09123330000، پشتیبانی 09124440000)
  سازمانی    http://localhost:3503   09123456789

  کد ورود همه: 12345 — رمز عبور: password123
  (چند ثانیه صبر کن تا اپ‌ها بالا بیایند؛ Ctrl+C برای بستن)
EOF
wait
