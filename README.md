# SplashEstate CRM

A complete multi-tenant Real Estate CRM SaaS system built with pure PHP and MySQL. No frameworks, lightweight, and beginner-friendly.

## Features

**CRM**
- **Multi-tenant Architecture** - Complete tenant isolation with subscription management
- **Lead Management** - Capture, tag, score, assign and convert leads to clients
- **Client Management** - Manage buyers, sellers, landlords, and tenants
- **Property Listings** - Filters, photo gallery, map, and matching leads by budget/interest
- **Deal Pipeline** - Drag-and-drop Kanban board with weighted pipeline value
- **Task Management** - Filters, calendar view, due-soon reminders
- **Activity Tracking & Audit Log** - Every change logged, searchable audit log
- **File Attachments** - Validated, tenant-isolated uploads

**Productivity**
- **Reports** - Sales funnel, pipeline, revenue by month, agent leaderboard, lead sources, grade distribution (CSV export)
- **Advanced Search** - Filters, saved searches, bulk status/assign/email/delete
- **Export** - CSV and print-ready "Save as PDF" pages
- **In-app Notifications** - Assignment, reminder and workflow notifications

**Automation**
- **Workflow Automation** - Trigger on events (lead created, deal won, ...) or on a schedule; conditions; actions such as email, task, field update, assign, tag, notification, webhook, create deal; delayed actions; dry-run testing
- **Lead Scoring** - Configurable rules, A-F grades, score history, recommended next actions
- **Outgoing Webhooks** - Signed (HMAC-SHA256) event delivery with retries and delivery log

**Platform**
- **Role-based Access Control** - Granular permissions, system and custom roles, team management
- **Subscription Billing** - Plans, quotas, Stripe Checkout and Billing Portal (demo mode without keys)
- **Email** - SMTP or log driver, branded templates, password reset
- **REST API** - Tenant API keys for lead capture
- **Operations** - Database migrations, cron scheduler, health checks, deploy script, automated tests

## Requirements

- PHP 7.0 or higher (tested on PHP 8.3)
- MySQL 5.7+ or MariaDB 10.3+
- Apache with mod_rewrite enabled (or Nginx, see below)
- PHP Extensions: PDO, pdo_mysql, mbstring, curl, json, fileinfo

## Installation

### 1. Clone or Download

```bash
git clone https://github.com/ahmedsaadawi13/SplashEstateCRM.git
cd SplashEstateCRM
```

### 2. Configure Environment

Copy the example environment file:

```bash
cp .env.example .env
```

Edit `.env` and update your database credentials:

```env
DB_HOST=localhost
DB_NAME=splashestate_crm
DB_USER=root
DB_PASS=your_password
BASE_URL=http://localhost/SplashEstateCRM/public
```

### 3. Create Database

Import the schema and demo data. Note: `database.sql` creates and uses a database
named `splashestate_crm`; edit its first lines if you need a different name.

```bash
mysql -u root -p < database.sql
php migrate.php migrate   # records the bundled migrations as applied
```

**Upgrading an existing install:** back up, pull the new code, then run
`php migrate.php migrate`. Migrations in `database/migrations/` bring older
installs up to the current schema. Use `php migrate.php status` to see what is
pending and `php migrate.php rollback` to undo the last batch.

### 3b. Schedule Background Jobs

Delayed workflow actions, scheduled workflows, webhook retries, task reminders
and cleanup run from `cron.php`. Add one crontab entry:

```cron
* * * * * php /path/to/SplashEstateCRM/cron.php >> /path/to/SplashEstateCRM/storage/logs/cron.log 2>&1
```

Run `php cron.php --list` to see the jobs, or `php cron.php <job>` to run one.

### 3c. Email

By default `MAIL_DRIVER=log` writes every email to `storage/logs/mail.log`
(useful in development). Set `MAIL_DRIVER=smtp` and the `MAIL_*` settings in
`.env` to send real email.

### 4. Set Permissions

Make the storage directory writable:

```bash
chmod -R 755 storage/
mkdir -p storage/uploads
chmod -R 777 storage/uploads
```

### 5. Configure Apache

Ensure your Apache configuration allows `.htaccess` overrides:

```apache
<Directory "/var/www/html/SplashEstateCRM">
    AllowOverride All
    Require all granted
</Directory>
```

Restart Apache:

```bash
sudo service apache2 restart
```

### 6. Access the Application

Open your browser and navigate to:

```
http://localhost/SplashEstateCRM/public
```

## Default Credentials

All demo accounts use the password **`password`**. Change or remove them before going live.

### Platform Admin
- **Email:** admin@splashestate.com

### Demo Tenant 1 (Sunset Realty Group)
- **Tenant admin:** john.smith@sunsetrealty.com
- **Agents:** sarah.johnson@sunsetrealty.com, mike.davis@sunsetrealty.com
- **API Key:** `sk_test_4eC39HqLyjWDarjtT1zdp7dc11111111111111111111111111111111`

### Demo Tenant 2 (Metro Properties Inc)
- **Tenant admin:** lisa.chen@metroproperties.com
- **Agents:** robert.williams@metroproperties.com, emily.brown@metroproperties.com
- **API Key:** `sk_test_7uH92KfDjkPNxywqR6vbm3ef22222222222222222222222222222222`

## REST API Documentation

### Authentication

All API requests require your tenant's API key in the `X-API-Key` header
(or `Authorization: Bearer <key>`). Keys passed in the query string are rejected.
Errors are returned as JSON: `{"success": false, "error": "...", "errors": {...}}`.

| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | `/api/leads` | List leads (`page`, `per_page` up to 100, `status`, `source`, `search`, `updated_since`) |
| POST | `/api/leads` or `/api/leads/create` | Create a lead (JSON body) |
| GET | `/api/leads/show/{id}` | Get one lead |

Creating a lead through the API counts against the plan's lead quota and fires the
same automation as the web app (lead scoring, workflows, webhooks).

### Create Lead

**Endpoint:** `POST /api/leads/create`

**Headers:**
```
Content-Type: application/json
X-API-Key: your_api_key_here
```

**Request Body:**
```json
{
    "first_name": "John",
    "last_name": "Doe",
    "email": "john.doe@example.com",
    "phone": "(555) 123-4567",
    "interest_type": "buy",
    "budget_min": 400000,
    "budget_max": 500000,
    "notes": "Looking for a 3-bedroom house"
}
```

**Success Response (201 Created):**
```json
{
    "success": true,
    "message": "Lead created successfully",
    "data": {
        "lead_id": 123,
        "lead": {
            "id": "123",
            "tenant_id": "1",
            "first_name": "John",
            "last_name": "Doe",
            "email": "john.doe@example.com",
            "phone": "(555) 123-4567",
            "source": "API",
            "status": "new",
            "interest_type": "buy",
            "budget_min": "400000.00",
            "budget_max": "500000.00",
            "notes": "Looking for a 3-bedroom house",
            "created_at": "2025-01-22 10:30:00"
        }
    }
}
```

**Error Response (401 Unauthorized):**
```json
{
    "error": "Invalid API key"
}
```

**Error Response (422 Validation Error):**
```json
{
    "error": "Validation failed",
    "errors": {
        "first_name": "First name is required",
        "email": "Invalid email format"
    }
}
```

**Error Response (429 Too Many Requests):**
```json
{
    "error": "Lead limit reached. Please upgrade your plan."
}
```

### List Leads

**Endpoint:** `GET /api/leads/index`

**Headers:**
```
X-API-Key: your_api_key_here
```

**Query Parameters:**
- `page` (optional) - Page number (default: 1)
- `per_page` (optional) - Records per page (default: 20, max: 100)
- `status` (optional) - Filter by status (new, contacted, qualified, etc.)

**Example Request:**
```
GET /api/leads/index?page=1&per_page=20&status=new
X-API-Key: sk_test_4eC39HqLyjWDarjtT1zdp7dc11111111111111111111111111111111
```

**Success Response (200 OK):**
```json
{
    "success": true,
    "data": {
        "leads": [
            {
                "id": "1",
                "first_name": "John",
                "last_name": "Doe",
                "email": "john.doe@example.com",
                "status": "new",
                "created_at": "2025-01-22 10:30:00"
            }
        ],
        "pagination": {
            "page": 1,
            "per_page": 20,
            "total": 45,
            "total_pages": 3
        }
    }
}
```

### cURL Examples

**Create Lead:**
```bash
curl -X POST http://localhost/SplashEstateCRM/public/api/leads/create \
  -H "Content-Type: application/json" \
  -H "X-API-Key: sk_test_4eC39HqLyjWDarjtT1zdp7dc11111111111111111111111111111111" \
  -d '{
    "first_name": "Jane",
    "last_name": "Smith",
    "email": "jane@example.com",
    "phone": "(555) 987-6543",
    "interest_type": "sell",
    "notes": "Wants to sell 4-bedroom house"
  }'
```

**List Leads:**
```bash
curl -X GET "http://localhost/SplashEstateCRM/public/api/leads?page=1&status=new" \
  -H "X-API-Key: sk_test_4eC39HqLyjWDarjtT1zdp7dc11111111111111111111111111111111"
```

## Architecture

### Directory Structure

```
SplashEstateCRM/
├── app/
│   ├── controllers/        # Controllers for all modules
│   │   ├── api/           # API controllers
│   │   ├── AuthController.php
│   │   ├── DashboardController.php
│   │   ├── LeadsController.php
│   │   ├── ClientsController.php
│   │   ├── PropertiesController.php
│   │   ├── DealsController.php
│   │   └── TasksController.php
│   ├── models/            # Data models
│   │   ├── User.php
│   │   ├── Lead.php
│   │   ├── Client.php
│   │   └── ...
│   ├── views/             # View templates
│   │   ├── layouts/
│   │   ├── auth/
│   │   ├── dashboard/
│   │   └── ...
│   ├── core/              # Core MVC classes
│   │   ├── Router.php
│   │   ├── Controller.php
│   │   ├── Model.php
│   │   ├── View.php
│   │   └── Database.php
│   └── helpers/           # Services and helper functions
│       ├── functions.php  # Global helpers (permissions, events, CSRF, notifications)
│       ├── WorkflowEngine.php, LeadScoringEngine.php, Scheduler.php
│       ├── WebhookService.php, StripeService.php, EmailService.php, SMTPMailer.php
│       └── ReportService.php, ExportService.php, BulkOperationService.php, SearchHelper.php
├── config/                # Configuration files
│   └── config.php
├── database/
│   └── migrations/        # Versioned schema changes (php migrate.php)
├── public/                # Public web root
│   ├── index.php         # Application entry point
│   ├── assets/
│   │   ├── css/
│   │   └── js/
│   └── .htaccess
├── storage/              # File uploads
│   └── uploads/
├── tests/                # Test files
├── cron.php              # Background jobs (run every minute from crontab)
├── migrate.php           # Migration CLI
├── deploy.sh             # Deployment script (checks, backup, migrations, tests)
├── database.sql          # Database schema with seed data
├── .env.example          # Environment configuration template
├── .htaccess            # Root htaccess
└── README.md
```

### Events

Every create/update/delete calls `dispatchEvent($tenantId, 'lead.created', $row)`
(see `app/helpers/functions.php`). One event fans out to lead scoring, matching
workflows and subscribed webhooks, so new automation only needs a new subscriber.

### Routing

`/{controller}/{method}/{params}` maps to `{Controller}Controller::{method}()`.
Controller segments are case-insensitive and may be kebab-case. Record detail URLs
(`/leads/view/5`) are handled by `show($id)` because `view()` is the template renderer.

### Multi-Tenant Design

Every table includes a `tenant_id` column for data isolation:
- All queries automatically filter by `tenant_id`
- API keys are tied to specific tenants
- File uploads are stored in tenant-specific directories
- Subscription quotas are enforced per tenant

### Security Features

- **CSRF Protection** - Tokens on every form and AJAX request; state-changing actions are POST-only
- **Password Hashing** - bcrypt via `password_hash()`
- **Brute-force Protection** - Login lockout after repeated failures (per email and per IP)
- **Password Reset** - Single-use, hashed, expiring tokens
- **Granular Permissions** - Every action checks an RBAC permission; no privilege escalation via roles
- **Input Validation** - Server-side validation on all inputs
- **SQL Injection Prevention** - Prepared statements; column/sort names whitelisted
- **File Upload Validation** - Real MIME detection, size limits, random names, served only through access-checked routes
- **XSS Prevention** - Output escaping with `htmlspecialchars()`
- **Session Security** - HttpOnly/SameSite cookies, session id rotation on login, idle timeout
- **Webhook Security** - HMAC-SHA256 signatures and SSRF protection for outgoing calls
- **API Key Authentication** - Header-only API keys

## Subscription Plans

### Default Plans

1. **Free Trial**
   - $0/month
   - 50 leads
   - 10 properties
   - 2 agents

2. **Professional**
   - $49/month
   - 500 leads
   - 100 properties
   - 10 agents

3. **Enterprise**
   - $199/month
   - Unlimited leads
   - Unlimited properties
   - Unlimited agents

### Quota Enforcement

The system automatically enforces quotas:
- Users cannot create new leads/properties when limits are reached
- API requests return 429 error when quota exceeded
- Upgrade prompts displayed in the UI

## Customization

### Adding a New Module

1. Create model in `app/models/YourModel.php`
2. Create controller in `app/controllers/YourController.php`
3. Create views in `app/views/your_module/`
4. Add routes (router handles convention-based routing automatically)

### Changing Colors/Styling

Edit `public/assets/css/style.css` to customize:
- Primary color: `#3498db`
- Secondary color: `#95a5a6`
- Success color: `#27ae60`
- Danger color: `#e74c3c`

## Testing

The test suite needs a MySQL user that can create databases; it rebuilds
`splashestate_crm_test` from `database.sql` on every run.

```bash
php tests/run_tests.php   # unit/integration tests
php tests/api_tests.php   # REST API tests
```

Coverage includes tenant isolation, routing, permissions, webhooks (signing,
SSRF protection, retries), Stripe signature verification, SMTP, migrations,
CSV export escaping and CSRF.

`tests/smoke.sh` logs in as a demo user against a running instance and requests
pages, flagging HTTP errors or PHP warnings:

```bash
tests/smoke.sh http://localhost:8080 john.smith@sunsetrealty.com password -- leads/index deals/index
```

## Deployment

### Production Checklist

1. **Environment Configuration**
   - Set `APP_ENV=production` in `.env`
   - Set `APP_DEBUG=false`
   - Use strong database passwords
   - Change all default passwords

2. **Security**
   - Enable HTTPS (SSL certificate)
   - Disable directory listing
   - Set proper file permissions (644 for files, 755 for directories)
   - Remove or restrict access to `database.sql`
   - Set `HEALTH_TOKEN` and `CRON_SECRET` if you use the detailed health check or HTTP cron
   - Configure `STRIPE_WEBHOOK_SECRET` before enabling the Stripe webhook

3. **Database**
   - Remove seed data in production
   - Set up regular backups
   - Optimize tables and indexes

4. **Performance**
   - Enable PHP opcode caching (OPcache)
   - Configure MySQL query cache
   - Set up CDN for static assets
   - Enable GZIP compression

5. **Monitoring**
   - Set up error logging
   - Monitor disk space for uploads
   - Track API usage
   - Set up uptime monitoring

### Apache Configuration

```apache
<VirtualHost *:80>
    ServerName yourdomain.com
    DocumentRoot /var/www/splashestate/public

    <Directory /var/www/splashestate/public>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/splashestate_error.log
    CustomLog ${APACHE_LOG_DIR}/splashestate_access.log combined
</VirtualHost>
```

### Nginx Configuration

```nginx
server {
    listen 80;
    server_name yourdomain.com;
    root /var/www/splashestate/public;
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php7.4-fpm.sock;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

## Troubleshooting

### Database Connection Error
- Verify database credentials in `.env`
- Ensure MySQL is running
- Check if database exists

### 404 Errors
- Verify Apache mod_rewrite is enabled
- Check `.htaccess` files exist
- Ensure `AllowOverride All` is set

### File Upload Errors
- Check directory permissions on `storage/uploads`
- Verify `MAX_UPLOAD_SIZE` in `.env`
- Check PHP `upload_max_filesize` and `post_max_size`

### Session Issues
- Ensure session directory is writable
- Check PHP session configuration
- Clear browser cookies

## Support

For issues and questions:
- GitHub Issues: https://github.com/ahmedsaadawi13/SplashEstateCRM/issues
- Documentation: https://docs.splashestate.com
- Email: ahmed.sha3ban13@gmail.com

## License

This project is licensed under the MIT License.

## Credits

Developed by SplashEstate Team
Built with PHP, MySQL, and vanilla JavaScript
