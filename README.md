# INCIDENT-REPORT

## Guard accounts

Admins can add a hired guard's login from **Settings > Users** or **Guard Oversight > Add Guard Account**. Enter the guard's name, username, school email, and an initial password (12-72 bytes; ASCII is recommended). Usernames and email addresses must be unique. The guard can sign in with either the username or school email and the password. The password is saved as a hash. Share the initial password with the guard securely; supervisors can monitor guards but cannot create accounts.

New guard accounts are stored in `accounts.json` in a data directory outside the project folder. By default this is a sibling folder named `<project-folder>-data`. For deployment, configure `INCIDENT_REPORT_DATA_DIR` to an absolute path that is writable by PHP and is not served by the web server. The app creates the directory and restricts the account file's permissions.

Guard check-ins and check-outs are stored in `attendance.json` in the same private data directory. Supervisors and admins can see each guard's current duty status in Guard Monitoring and review recent attendance events from the guard's details page. Guards can only record attendance for their own account.

Guard violation categories focus on observable behavior: uniform/dress code, physical altercation, bullying/harassment concern, property damage, unauthorized area/access, disruptive or unsafe behavior, and other. "Late" and "Truancy" are excluded because guards may not have the class or attendance information needed to verify them. For an arrival-time concern, record the exact observed time and facts under "Other"; avoid assumptions.