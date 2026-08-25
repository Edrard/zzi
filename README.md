# Znuny & Zabbix Integration Workspace

This application is a specialized Laravel-based integration workspace designed to bridge **Zabbix** monitoring events with **Znuny / OTRS** ticketing workflows.

## Key Features

- **Current Zabbix Problems Dashboard**: View and filter active Zabbix problems, group duplicates, and see linked Znuny tickets.
- **Znuny Ticket Workspace**: Manage, create, and review Znuny tickets with advanced cache-backed lookups for Agents, Customers, and Queues.
- **Scheduled Ticket Creation**: Automated, resilient background scheduling to attempt Znuny ticket creation with duplicate guards and manual retry capabilities.
- **Inline Image Caching & Warming**: Pre-warms and caches Znuny inline images to ensure fast rendering in ticket articles.
- **Operational Tooling**: Built-in settings management and audit logs.

## Requirements

- **PHP**: 8.3+
- **Database**: SQLite, MySQL, or PostgreSQL
- **Cache/Locks/Queue**: Redis (if configured for production queues/locks)
- **Node.js/NPM**: For building frontend assets via Vite
- **Integrations**: Zabbix API and Znuny / OTRS API access

## Installation

1. **Clone the repository** and install dependencies:
   ```bash
   composer install
   npm install --ignore-scripts
   ```

2. **Configure Environment**:
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```
   > **Note:** Configure your `.env` with appropriate database credentials, Redis settings, and API URLs for Zabbix and Znuny. Do not use real production credentials in local development environments.

3. **Database Setup**:
   ```bash
   php artisan migrate
   ```

4. **Frontend Assets**:
   ```bash
   npm run build
   ```

5. **Run the Application**:
   Serve the application locally:
   ```bash
   php artisan serve
   ```
   Start the queue worker and scheduler (for background ticket creation/syncing):
   ```bash
   php artisan queue:work
   php artisan schedule:work
   ```

## Security Guidelines

- Keep all infrastructure credentials, passwords, and API tokens in your environment variables (`.env`).
- Never commit your `.env` file to version control.
- Use `.env.example` strictly for safe, sanitized placeholders.

## Testing

You can run the test suite safely using the provided script which ensures isolation:

```bash
bash scripts/phpunit-safe.sh
```
This test wrapper uses an isolated test configuration to prevent accidental modification of your local or staging state.

## License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

---
Created with the support of **Vamark** — [https://vamark.ua](https://vamark.ua).
