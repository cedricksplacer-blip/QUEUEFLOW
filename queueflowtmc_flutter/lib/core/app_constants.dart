/// ============================================================
/// QUEUEFLOW TMC — APPLICATION CONSTANTS
///
/// Identity, asset paths and the vocabulary shared with Laravel.
///
/// Anything the server also stores lives here as a `wire` value rather than as
/// display text, so a screen can never drift from the enum the API speaks and
/// an unknown value coming back from the database is handled as unknown rather
/// than guessed at.
/// ============================================================
library;

class AppConstants {
  AppConstants._();

  // ==========================================================
  // IDENTITY
  // ==========================================================

  static const String appName = 'QUEUEFLOW TMC';
  static const String appTagline = 'Smart Campus Queue Management Platform';

  /// Shown under the wordmark and in the drawer.
  static const String schoolName = 'TMC College';

  /// Short form for app-bar and splash contexts where the full wordmark does
  /// not fit.
  static const String appShortName = 'QueueFlow';

  static const String backgroundImage = 'assets/images/B.jpg';
  static const String logoImage = 'assets/images/L.png';
  static const String launcherIcon = 'assets/images/I.png';

  /// The photograph shown on a profile card for an account that has not
  /// uploaded one of its own.
  ///
  /// A bundled asset rather than a drawn placeholder, so the card reads as a
  /// photograph from the first frame instead of snapping to initials once the
  /// network answers. It is only ever a *fallback*: a missing or undecodable
  /// file drops the card back to the initials disc, so pointing this at a file
  /// that is not there yet degrades rather than breaks.
  ///
  /// Swap the filename to change the portrait.
  static const String profilePhoto = 'assets/images/avatar.jpg';

  /// Verified single-campus address, matching
  /// OfficeStation::CAMPUS_LOCATION on the Laravel side.
  static const String campusLocation =
      'Trinidad Municipal College, President Carlos P. Garcia St., '
      'Santo Nino, Trinidad, Bohol';

  /// Shown on the About and Settings screens. It is the same sentence as
  /// [appTagline], kept separate so those screens can wrap it differently
  /// without editing the brand.
  static const String appDescription =
      'Skip the line. Take a queue ticket, watch your number move, and get '
      'notified the moment it is called at the Trinidad Municipal College '
      'offices.';

  // ==========================================================
  // QUEUE OFFICES
  // ==========================================================
  //
  // The offices a student may take a ticket at, in the order they are listed on
  // every screen. This is the whole list: the app offers these six and nothing
  // else, so a row left behind in the database — a closed counter, a per-course
  // desk, a decommissioned office — cannot appear in a student's picker and
  // strand them at a window that will never call a number.
  //
  // Matched against the catalogue Laravel returns by name, case-insensitively,
  // because the codes have changed between campus databases and a name is what
  // the student reads on the sign above the window.

  static const List<String> queueOffices = <String>[
    'Registrar',
    'Cashier',
    'Admissions',
    'SASO',
    'Scholarship Office',
    'School Clinic',
  ];

  /// Whether [name] is a Registrar row.
  ///
  /// The Registrar is seven offices on the server, each named "Registrar " with
  /// the course word appended (Registrar BSIT, Registrar BSOA, ...). They all
  /// stand in for the same window, so a student-facing screen has to treat
  /// every one of them as the single "Registrar" entry. Matched by prefix
  /// on purpose: the course word after the name changes per row, and only the
  /// "Registrar" stem is stable.
  static bool isRegistrarName(String name) {
    return name.trim().toLowerCase().startsWith('registrar');
  }

  /// Whether [name] is one of the offices above.
  static bool isQueueOffice(String name) {
    if (isRegistrarName(name)) {
      return true;
    }

    final String needle = name.trim().toLowerCase();

    return queueOffices.any((String office) => office.toLowerCase() == needle);
  }

  /// The position of [name] in [queueOffices], or a large number when it is not
  /// one of them. Used to keep every screen in the same order. Every Registrar
  /// row ranks as the single Registrar entry (0).
  static int queueOfficeRank(String name) {
    if (isRegistrarName(name)) {
      return 0;
    }

    final String needle = name.trim().toLowerCase();

    for (int i = 0; i < queueOffices.length; i++) {
      if (queueOffices[i].toLowerCase() == needle) {
        return i;
      }
    }

    return queueOffices.length;
  }

  // ==========================================================
  // REGISTRAR COURSES
  // ==========================================================
  //
  // The seven academic colleges the Registrar runs a separate queue for, in the
  // order they are offered in the course picker.
  //
  // The Registrar is seven offices on the server (REG-BSIT ... REG-BSOA), each
  // with its own 100-slot batch and its own assigned staff member, so the codes
  // below are the wire values that identify both a course row and the office
  // serving it. One list, rather than a set repeated per screen, is what keeps
  // the picker and the "your course" badge reading from the same vocabulary as
  // the database.

  static const List<String> registrarCourses = <String>[
    'BACOMM',
    'BAPOLSCI',
    'BEED',
    'BSCRIM',
    'BSED',
    'BSIT',
    'BSOA',
  ];

  /// Whether [code] is one of the Registrar courses above.
  static bool isRegistrarCourse(String? code) {
    if (code == null) {
      return false;
    }

    final String needle = code.trim().toUpperCase();

    return registrarCourses.contains(needle);
  }

  /// The office code that serves [course], or null when [course] is not a
  /// Registrar course.
  ///
  /// This is the join between the two halves of the Registrar: the server keys
  /// the office off `REG-<COURSE>`, so deriving it here means the client never
  /// has to hardcode a second table of office ids.
  static String? registrarOfficeCode(String? course) {
    if (!isRegistrarCourse(course)) {
      return null;
    }

    return 'REG-${course!.trim().toUpperCase()}';
  }

  /// The prefix every per-course Registrar office code starts with.
  ///
  /// Used to recognise the Registrar rows in an office payload without matching
  /// on display text, so a renamed office still groups correctly.
  static const String registrarOfficePrefix = 'REG-';

  /// Whether an office [code] is one of the per-course Registrar offices.
  static bool isRegistrarOfficeCode(String? code) {
    return code != null && code.toUpperCase().startsWith(registrarOfficePrefix);
  }

  // ==========================================================
  // OFFICE STATUS (mirrors App\Models\Office)
  // ==========================================================
  //
  // The daily online/offline switch. "Offline" never means decommissioned -
  // is_active still owns that - it means "the staff have closed this counter
  // for now and it is not accepting queue tickets."

  static const String officeStatusOnline = 'online';
  static const String officeStatusOffline = 'offline';

  // ==========================================================
  // QUEUE TICKET STATUS (mirrors App\Models\QueueTicket)
  // ==========================================================

  static const String statusWaiting = 'waiting';
  static const String statusServing = 'serving';
  static const String statusServed = 'served';
  static const String statusCancelled = 'cancelled';
  static const String statusNoShow = 'no_show';

  /// A status a student can still be called from.
  static const List<String> activeStatuses = <String>[
    statusWaiting,
    statusServing,
  ];

  /// Finished states kept in History (never return to waiting/serving).
  static const List<String> terminalStatuses = <String>[
    statusServed,
    statusCancelled,
    statusNoShow,
  ];

  // ==========================================================
  // PRIORITY (mirrors App\Models\QueueTicket)
  // ==========================================================

  static const String priorityRegular = 'regular';
  static const String priorityPwd = 'pwd';

  // ==========================================================
  // ACCOUNT ROLES (mirrors App\Models\User)
  // ==========================================================
  //
  // Only `student` reaches the interface: this build is the student app. The
  // other two wire values are still parsed, because the users table stores them
  // and a staff or admin token must be recognised and refused rather than
  // treated as an unknown account.

  static const String roleAdmin = 'admin';
  static const String roleStaff = 'staff';
  static const String roleStudent = 'student';

  // ==========================================================
  // SESSION KEEPING
  // ==========================================================

  /// The last signed-in account. The campus document itself is keyed by
  /// the local backend, which owns it.
  static const String sessionUserKey = 'qf_student_user';

  /// The Sanctum bearer token for that account.
  ///
  /// Kept beside the user rather than in its own store because the two have
  /// the same lifetime: a token without its user is a request that 401s, and a
  /// user without their token is a shell that cannot read anything. Written
  /// and cleared together, they cannot drift.
  ///
  /// No password is ever written to disk: it is posted to `/auth/login` and
  /// dropped as soon as the server has exchanged it for a token.
  static const String sessionTokenKey = 'qf_api_token';
}
