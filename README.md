TO BE SUBMITTED TO LEBRON



# Graduate School System

A Laravel and React application for graduate-school workflows, including comprehensive exam applications, defense requests, schedules, payments, and related administrative processes.

## Requirements

- PHP 8.2 or newer and Composer
- Node.js and npm
- MySQL (for example, through XAMPP)

The project uses Laravel 12, Inertia, React, TypeScript, and Vite.

## Run locally on Windows

1. Start MySQL from XAMPP and create a local database. The example configuration uses `grad_integration_db`.
2. Create your own `.env` file using `.env.example` as a reference. Set the database name, username, and password for your local MySQL setup. Keep `.env` private and do not commit it.
3. From PowerShell in the project folder, install dependencies and initialize the application:

   ```powershell
   composer install
   npm install
   php artisan key:generate
   php artisan migrate --seed
   ```

   If `.env` does not exist yet, create it before running `key:generate`. Review its database settings before migrating.
4. To create the five local demo Proctor accounts, run:

   ```powershell
   php artisan demo:proctor-accounts
   ```

5. Start the local application:

   ```powershell
   npm.cmd run dev
   ```

   This starts the Laravel server, Vite, the queue listener, and the scheduler. Open [http://127.0.0.1:8001](http://127.0.0.1:8001).

### Local demo accounts

Demo accounts use the password `password` when newly created. The five Proctor accounts are `proctor1@uic.edu.ph` through `proctor5@uic.edu.ph`. The command prints the account list and preserves passwords for already-existing demo accounts.

Use demo credentials only in a local or test environment. Demo Proctors can view schedules assigned to their own accounts. Do not use the shared demo password for real accounts.

## Comprehensive exam workflow

1. **Student application:** A student submits a comprehensive exam application and can track its review and payment status.
2. **Registrar review:** The Registrar reviews the application. The AA can approve it for payment after the Registrar review is complete.
3. **Payment:** After the required application approvals, the student can submit payment information and a receipt. The AA reviews the exam payment receipt.
4. **Exam permission:** Once the application and exam payment are approved, the AA can mark the student **Allowed** or **Not allowed** to take the exam. Coordinators can see the decision. Scheduling is blocked until the student is allowed.
5. **Scheduling:** A Coordinator can schedule an eligible student, select a date with the date picker, set the time and room, and assign Proctor accounts. Past exam dates are not accepted. The schedule belongs to the student and can be edited.
6. **Schedule updates:** A reason is required when editing an existing schedule, but not when creating the initial schedule. The student dashboard shows the schedule, highlights updated schedules, displays the update reason, and sends an in-app notification when a schedule is created or changed.

### Application review and evaluation

- Only the Administrative Assistant can mark a student's exam response as evaluated.
- The Registrar's exam application table has a separate **AA Evaluation** status so the Registrar can see whether the response is evaluated.
- The AA approval notification asks the student to pay **Php3,120.00** for Graduate Studies, covering the comprehensive examination fee and CAR fee, and to submit payment. The same wording is used for master's and doctoral students.
- The AA has a confirmation-protected reset action for removing an application and its related exam subjects, payment/receipt, schedule, and comprehensive-exam notifications so the student can submit again.

## Other system areas

- **Defense requests:** Students can submit defense requests and supporting information for administrative review and scheduling.
- **Honorarium workflows:** The application includes honorarium tracking and related review and finance-document workflows. No real payments are executed by the local demo.
- **Role-based access:** The interface and server enforce separate permissions for Students, Coordinators, the Administrative Assistant, the Registrar, Proctors, and other administrative roles.
- **Notifications and dashboards:** Relevant users see workflow updates and role-specific information in the application.

Some workflows depend on institutional services or credentials. In particular, local demo accounts do not verify a student's eligibility through a school portal. Configure the required integrations before relying on those checks in a live environment.

## Checks

Run the automated tests and frontend checks with:

```powershell
php artisan test
npm.cmd run types
npm.cmd run build
```

Tests use isolated test data and should not be treated as a production acceptance test.

## Configuration and security

- `.env.example` is a template, not a place to store real credentials. Use a private local `.env` and never commit it.
- Configure Google OAuth callback URLs and other integrations for your own local or hosted URL before using those features.
- Local email configuration may write messages to Laravel logs instead of sending email. Configure a real mail transport before enabling external email delivery.
- Review [docs/integration-setup.md](docs/integration-setup.md) for additional integration notes.
