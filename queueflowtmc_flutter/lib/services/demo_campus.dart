import '../core/app_constants.dart';
import '../models/announcement.dart';
import '../models/campus_catalog.dart';

/// ============================================================
/// QUEUEFLOW TMC — OFFLINE CAMPUS SEED
///
/// The campus, written down once. This app no longer talks to a server,
/// so this document is where every office, course, announcement, account
/// and queue ticket comes from.
///
/// [buildDemoCampus] returns the same shape the models serialise to, so
/// the seed and the persisted local database cannot drift apart: the
/// backend stores this document verbatim and hydrates it back through the
/// ordinary `fromMap` constructors.
///
/// Timestamps are relative to [DateTime.now], so the seeded queues always
/// look like they filled up this morning rather than on a fixed date.
/// ============================================================

/// Passwords for the seeded accounts. One per role, so signing in as any
/// of the demo accounts is guessable without a manual.
const String adminDemoPassword = 'admin123';
const String staffDemoPassword = 'staff123';
const String studentDemoPassword = 'student123';

/// College IDs of the three demo sign-ins, held here rather than inline so
/// the seed and anything that quotes a credential cannot drift apart.
const String adminDemoStudentId = '2025-00001';
const String staffDemoStudentId = '2025-00002';
const String inactiveStaffDemoStudentId = '2025-00003';
const String studentDemoStudentId = '2025-00145';

/// Builds the starting campus document.
///
/// The shape is the backend's whole persisted state: `settings`,
/// `courses`, `offices`, `announcements`, `accounts`, `batches`,
/// `tickets` and the per-office `sequences` that hand out queue numbers.
Map<String, dynamic> buildDemoCampus() {
  final DateTime now = DateTime.now();

  String ago(int minutes) =>
      now.subtract(Duration(minutes: minutes)).toIso8601String();

  DateTime openedAtToday(int hour, int minute) =>
      DateTime(now.year, now.month, now.day, hour, minute);

  // ==========================================================
  // SETTINGS
  // ==========================================================

  const SystemSettings settings = SystemSettings(
    siteName: AppConstants.appName,
    collegeName: 'Trinidad Municipal College',
    systemDescription:
        'Campus queue desk for student clearances, payments and records.',
    contactEmail: 'registrar@tmc.edu.ph',
    contactNumber: '(038) 555-0142',
    defaultWaitingTime: 5,
    defaultOpeningTime: '07:00',
    defaultClosingTime: '17:00',
    operationalStartTime: '07:00',
    operationalEndTime: '17:00',
    maxActiveTicketsPerStudent: 1,
    maxSlotsPerStation: 100,
    supportedCourses: <String>['BSIT', 'BSED', 'BSN', 'BSCS', 'BSA'],
    supportedYearLevels: <String>[
      '1st Year',
      '2nd Year',
      '3rd Year',
      '4th Year',
    ],
    maintenanceMessage: '',
    primaryColor: '#073B91',
    secondaryColor: '#7C3AED',
    accentColor: '#FFB300',
  );

  // ==========================================================
  // COURSES
  // ==========================================================

  // Every course queues at the Registrar, which is the office that owns
  // enrolment and curriculum records. Courses 3-5 are not course-specific
  // offices, so they inherit that same office rather than inventing one.
  final List<Course> courses = <Course>[
    const Course(
      id: 1,
      code: 'BSIT',
      name: 'BS Information Technology',
      officeId: 2,
    ),
    const Course(id: 2, code: 'BSED', name: 'BS Education', officeId: 2),
    const Course(id: 3, code: 'BSN', name: 'BS Nursing', officeId: 2),
    const Course(id: 4, code: 'BSCS', name: 'BS Computer Science', officeId: 2),
    const Course(id: 5, code: 'BSA', name: 'BS Accountancy', officeId: 2),
  ];

  // ==========================================================
  // OFFICES, STATIONS AND SERVICES
  // ==========================================================

  OfficeStation station(int id, int officeId, String name, String location) =>
      OfficeStation(
        id: id,
        officeId: officeId,
        name: name,
        location: location,
        maxDailySlots: 100,
        targetDailyStudents: 60,
        minDailyStudents: 40,
        isActive: true,
      );

  OfficeService service(
    int id,
    int officeId,
    int stationId,
    String name, [
    String? description,
  ]) => OfficeService(
    id: id,
    officeId: officeId,
    stationId: stationId,
    name: name,
    description: description,
    isActive: true,
  );

  final List<Office> offices = <Office>[
    Office(
      id: 1,
      name: 'Cashier',
      code: 'CASH',
      status: AppConstants.officeStatusOnline,
      description: 'Payments, accountabilities and student fees.',
      openingTime: '07:00',
      closingTime: '17:00',
      maxSlots: 100,
      services: <OfficeService>[
        service(1, 1, 1, 'Tuition payment', 'Final term and instalment fees.'),
        service(2, 1, 1, 'Accountability clearance'),
        service(3, 1, 1, 'ID replacement', 'For a lost or damaged school ID.'),
      ],
      stations: <OfficeStation>[
        station(1, 1, 'Window A', 'Ground Floor, Administration Building'),
      ],
    ),
    Office(
      id: 2,
      name: 'Registrar',
      code: 'REG',
      status: AppConstants.officeStatusOnline,
      description: 'Enrolment, transcripts and student records.',
      openingTime: '07:00',
      closingTime: '17:00',
      maxSlots: 80,
      services: <OfficeService>[
        service(
          4,
          2,
          2,
          'Certificate of Registration',
          'Your COR for this term.',
        ),
        service(5, 2, 2, 'Transcript request'),
        service(6, 2, 2, 'Certificate of Good Moral'),
      ],
      stations: <OfficeStation>[
        station(2, 2, 'Registrar Window 1', 'Main Building, 2nd Floor'),
      ],
    ),
    Office(
      id: 3,
      name: 'Admissions',
      code: 'ADM',
      status: AppConstants.officeStatusOnline,
      description: 'Entrance, admission requirements and credential evaluation.',
      openingTime: '07:00',
      closingTime: '17:00',
      maxSlots: 80,
      services: <OfficeService>[
        service(7, 3, 3, 'Entrance exam schedule'),
        service(8, 3, 3, 'Admission requirements'),
        service(9, 3, 3, 'Transfer credential evaluation'),
      ],
      stations: <OfficeStation>[
        station(3, 3, 'Admissions Window', 'Main Building, Ground Floor'),
      ],
    ),
    Office(
      id: 4,
      name: 'School Clinic',
      code: 'CLNC',
      status: AppConstants.officeStatusOnline,
      description:
          'Student health services. Closed while the new clinic wing is '
          'fitted out.',
      isActive: false,
      openingTime: '08:00',
      closingTime: '12:00',
      maxSlots: 40,
      services: <OfficeService>[service(10, 4, 4, 'Medical consultation')],
      stations: <OfficeStation>[
        station(4, 4, 'Clinic Reception', 'Main Building, Ground Floor'),
      ],
    ),
    Office(
      id: 5,
      name: 'Scholarship Office',
      code: 'SCHO',
      status: AppConstants.officeStatusOnline,
      description:
          'Scholarship applications, renewals and release of benefits.',
      openingTime: '08:00',
      closingTime: '17:00',
      maxSlots: 50,
      services: <OfficeService>[
        service(
          11,
          5,
          5,
          'Scholarship application',
          'For first-time and renewing applicants.',
        ),
        service(12, 5, 5, 'Benefit release'),
      ],
      stations: <OfficeStation>[
        station(5, 5, 'Scholarship Window', 'Main Building, 2nd Floor'),
      ],
    ),
    Office(
      id: 6,
      name: 'SASO',
      code: 'SASO',
      status: AppConstants.officeStatusOnline,
      description:
          'Student affairs: organisations, IDs, uniforms and welfare referrals.',
      openingTime: '08:00',
      closingTime: '17:00',
      maxSlots: 20,
      services: <OfficeService>[
        service(13, 6, 6, 'Student organisation registration'),
        service(14, 6, 6, 'Uniform and ID release'),
      ],
      stations: <OfficeStation>[
        station(6, 6, 'SASO Front Desk', 'Main Building, Ground Floor'),
      ],
    ),
  ];

  // ==========================================================
  // ACCOUNTS
  // ==========================================================

  final List<Map<String, dynamic>> accounts = <Map<String, dynamic>>[];

  int accountId = 0;

  void addAccount({
    required String name,
    required String email,
    required String role,
    required String password,
    String? studentId,
    String? course,
    String? block,
    String? yearLevel,
    String? phone,
    String? avatar,
    bool isActive = true,
    int createdDaysAgo = 210,
  }) {
    accountId++;

    accounts.add(<String, dynamic>{
      'id': accountId,
      'name': name,
      'email': email,
      'role': role,
      'password': password,
      'student_id': studentId,
      'course': course,
      'block': block,
      'year_level': yearLevel,
      'phone': phone,
      'avatar': avatar,
      'is_active': isActive,
      'created_at': now
          .subtract(Duration(days: createdDaysAgo))
          .toIso8601String(),
    });
  }

  // Staff and administrators first, so the demo sign-ins are ids 1-3.
  //
  // Everyone signs in with a college ID, staff included: the login form takes
  // an ID and nothing else, so an account without one could never reach its
  // shell. Staff IDs sit below the student range (students start at 145) to
  // keep the two series from ever colliding.
  addAccount(
    name: 'Maria Santos',
    email: 'admin@tmc.edu.ph',
    role: AppConstants.roleAdmin,
    password: adminDemoPassword,
    studentId: adminDemoStudentId,
    phone: '09171234567',
  );

  addAccount(
    name: 'Ramon Cruz',
    email: 'staff@tmc.edu.ph',
    role: AppConstants.roleStaff,
    password: staffDemoPassword,
    studentId: staffDemoStudentId,
    phone: '09181234567',
  );

  addAccount(
    name: 'Liza Mendoza',
    email: 'liza@tmc.edu.ph',
    role: AppConstants.roleStaff,
    password: staffDemoPassword,
    studentId: inactiveStaffDemoStudentId,
    phone: '09191234567',
    isActive: false,
  );

  // Students. The first four are the ones the demo credentials belong to;
  // the rest exist so the queues have a believable crowd behind them.
  const List<({String name, String course, String year, String block})>
  demoStudents = <({String name, String course, String year, String block})>[
    (name: 'Juan Dela Cruz', course: 'BSIT', year: '2nd Year', block: '1'),
    (name: 'Ana Reyes', course: 'BSED', year: '2nd Year', block: '2'),
    (name: 'Carlo Mendoza', course: 'BSN', year: '3rd Year', block: '1'),
    (name: 'Debbie Lim', course: 'BSCS', year: '1st Year', block: '3'),
  ];

  const List<({String name, String course, String year, String block})>
  crowdStudents = <({String name, String course, String year, String block})>[
    (name: 'Ethan Abella', course: 'BSA', year: '3rd Year', block: '2'),
    (name: 'Bea Fernandez', course: 'BSIT', year: '2nd Year', block: '2'),
    (name: 'Frank.Ordinalez', course: 'BSN', year: '2nd Year', block: '3'),
    (name: 'Grace Yu', course: 'BSED', year: '3rd Year', block: '1'),
    (name: 'Hector Villegas', course: 'BSCS', year: '2nd Year', block: '2'),
    (name: 'Ivy plantilla', course: 'BSIT', year: '3rd Year', block: '3'),
    (name: 'Joel Barongan', course: 'BSA', year: '2nd Year', block: '1'),
    (name: 'Kara Dizon', course: 'BSN', year: '1st Year', block: '2'),
    (name: 'Loren Almeda', course: 'BSED', year: '2nd Year', block: '3'),
    (name: 'Mona Toledo', course: 'BSCS', year: '3rd Year', block: '1'),
    (name: 'Nico Borja', course: 'BSIT', year: '1st Year', block: '2'),
    (name: 'Oscar Uy', course: 'BSA', year: '4th Year', block: '3'),
  ];

  const List<String> domains = <String>[
    'tmc.edu.ph',
    'tmc.edu.ph',
    'tmc.edu.ph',
  ];

  int studentNumber = 144;

  void addStudent({
    required String name,
    required String course,
    required String year,
    required String block,
  }) {
    final String handle = name
        .toLowerCase()
        .split(' ')
        .take(2)
        .map((String part) => part.replaceAll(RegExp('[^a-z]'), ''))
        .join('.');

    studentNumber += 1;

    addAccount(
      name: name,
      email: '$handle@${domains[studentNumber % domains.length]}',
      role: AppConstants.roleStudent,
      password: studentDemoPassword,
      studentId: '2025-${studentNumber.toString().padLeft(5, '0')}',
      course: course,
      yearLevel: year,
      block: block,
      createdDaysAgo: 120 + (studentNumber % 60),
    );
  }

  for (final ({String name, String course, String year, String block}) s
      in demoStudents) {
    addStudent(name: s.name, course: s.course, year: s.year, block: s.block);
  }

  for (final ({String name, String course, String year, String block}) s
      in crowdStudents) {
    addStudent(name: s.name, course: s.course, year: s.year, block: s.block);
  }

  final List<int> studentIds = accounts
      .where((Map<String, dynamic> a) => a['role'] == AppConstants.roleStudent)
      .map((Map<String, dynamic> a) => a['id'] as int)
      .toList(growable: false);

  // ==========================================================
  // BATCHES
  // ==========================================================

  // One open batch per office that is taking numbers. The SASO batch is
  // seeded full and the School Clinic is inactive with none at all, so every
  // branch of an office's "why can I not queue here" copy is reachable.
  final List<QueueBatch> batches = <QueueBatch>[
    QueueBatch(
      id: 1,
      officeId: 1,
      batchNo: 1,
      status: 'open',
      maxSlots: 100,
      openedAt: null,
    ),
    QueueBatch(
      id: 2,
      officeId: 2,
      batchNo: 1,
      status: 'open',
      maxSlots: 80,
      openedAt: null,
    ),
    QueueBatch(
      id: 3,
      officeId: 3,
      batchNo: 1,
      status: 'open',
      maxSlots: 80,
      openedAt: null,
    ),
    QueueBatch(
      id: 4,
      officeId: 5,
      batchNo: 1,
      status: 'open',
      maxSlots: 50,
      openedAt: null,
    ),
    QueueBatch(
      id: 5,
      officeId: 6,
      batchNo: 1,
      status: 'full',
      maxSlots: 20,
      submittedCount: 20,
      servedCount: 20,
      openedAt: null,
    ),
  ];

  batches.replaceRange(
    0,
    batches.length,
    batches
        .map(
          (QueueBatch b) => QueueBatch(
            id: b.id,
            officeId: b.officeId,
            batchNo: b.batchNo,
            status: b.status,
            maxSlots: b.maxSlots,
            submittedCount: b.submittedCount,
            servedCount: b.servedCount,
            openedAt: openedAtToday(7, 0),
            closedAt: null,
            completedAt: null,
          ),
        )
        .toList(),
  );

  // ==========================================================
  // TICKETS
  // ==========================================================

  final List<Map<String, dynamic>> tickets = <Map<String, dynamic>>[];
  final Map<String, int> sequences = <String, int>{};

  int nextTicketId = 1;
  int cursor = 0;

  int owner() => studentIds[cursor++ % studentIds.length];

  int nextStationFor(int officeId) => switch (officeId) {
    1 => 1,
    2 => 2,
    3 => 3,
    4 => 4,
    5 => 5,
    _ => 6,
  };

  int batchFor(int officeId) => switch (officeId) {
    1 => 1,
    2 => 2,
    3 => 3,
    5 => 4,
    6 => 5,
    _ => 0,
  };

  String officeCode(int officeId) =>
      offices.firstWhere((Office o) => o.id == officeId).code;

  /// Adds one ticket row, stamping times relative to now so the seeded
  /// history always sits in the past.
  void addTicket({
    required int officeId,
    required int serviceId,
    required int courseId,
    required int number,
    required String status,
    required int createdMinutesAgo,
    String priority = AppConstants.priorityRegular,
    int? calledMinutesAgo,
    int? servedMinutesAgo,
    int? servedBy,
  }) {
    final int stationId = nextStationFor(officeId);
    final int batchId = batchFor(officeId);
    final String code = officeCode(officeId);

    tickets.add(<String, dynamic>{
      'id': nextTicketId++,
      'queue_number': '$code-${number.toString().padLeft(3, '0')}',
      'status': status,
      'priority': priority,
      'purpose': null,
      'year_level': null,
      'has_evidence': priority == AppConstants.priorityPwd,
      'student_id': owner(),
      'office_id': officeId,
      'service_id': serviceId,
      'station_id': stationId,
      'course_id': courseId,
      'batch_id': batchId,
      'served_by': servedBy,
      'called_at': calledMinutesAgo == null ? null : ago(calledMinutesAgo),
      'served_at': servedMinutesAgo == null ? null : ago(servedMinutesAgo),
      'created_at': ago(createdMinutesAgo),
    });
  }

  // One queue per office that has a batch: a stretch of served tickets
  // from earlier, one number at the counter, then the people waiting.
  const List<({int office, int service, int course, int served, int waiting})>
  queuePlan =
      <({int office, int service, int course, int served, int waiting})>[
        (office: 1, service: 1, course: 3, served: 11, waiting: 3),
        (office: 2, service: 4, course: 2, served: 7, waiting: 2),
        (office: 3, service: 7, course: 1, served: 6, waiting: 3),
        (office: 5, service: 11, course: 1, served: 4, waiting: 2),
        (office: 6, service: 13, course: 2, served: 20, waiting: 0),
      ];

  for (final ({int office, int service, int course, int served, int waiting})
      plan
      in queuePlan) {
    int number = 0;

    for (int i = 0; i < plan.served; i++) {
      number++;

      // Spread the morning's traffic backwards from an hour ago.
      final int created = 150 - (i * 8);
      final int called = created - 5;
      final int servedAt = called - 4;

      addTicket(
        officeId: plan.office,
        serviceId: plan.service,
        courseId: plan.course,
        number: number,
        status: AppConstants.statusServed,
        createdMinutesAgo: created,
        calledMinutesAgo: called,
        servedMinutesAgo: servedAt,
        servedBy: 2,
      );
    }

    if (plan.waiting > 0) {
      // The number at the counter.
      number++;

      addTicket(
        officeId: plan.office,
        serviceId: plan.service,
        courseId: plan.course,
        number: number,
        status: AppConstants.statusServing,
        createdMinutesAgo: 26,
        calledMinutesAgo: 3,
        servedBy: 2,
      );

      for (int i = 0; i < plan.waiting; i++) {
        number++;

        addTicket(
          officeId: plan.office,
          serviceId: plan.service,
          courseId: plan.course,
          number: number,
          status: AppConstants.statusWaiting,
          createdMinutesAgo: 24 - (i * 6),
          // The first name in each queue holds a PWD ticket, so the
          // console's priority ordering has something to sort.
          priority: i == 0
              ? AppConstants.priorityPwd
              : AppConstants.priorityRegular,
        );
      }
    }

    sequences[officeCode(plan.office)] = number;
  }

  // ==========================================================
  // ANNOUNCEMENTS
  // ==========================================================

  final List<Announcement> announcements = <Announcement>[
    Announcement(
      id: 1,
      title: 'School Clinic closed while the new wing is fitted out',
      body:
          'The School Clinic will be closed until further notice. Urgent '
          'cases should be referred to the Student Services desk in the Main '
          'Building, which will direct you to the nearest facility.',
      author: 'Maria Santos',
      office: 'School Clinic',
      createdAt: DateTime.parse(ago(180)),
    ),
    Announcement(
      id: 2,
      title: 'Cashier closes at 5:00 PM this week',
      body:
          'To clear the end-of-term backlog the Cashier will stop issuing '
          'numbers at 5:00 PM from Monday. Anyone already in line will be '
          'called.',
      author: 'Ramon Cruz',
      office: 'Cashier',
      createdAt: DateTime.parse(ago(1440)),
    ),
    Announcement(
      id: 3,
      title: 'Scholarship renewals now queue at the Scholarship Office',
      body:
          'Scholarship renewals can be taken through the Scholarship Office '
          'alongside first-time applications. Bring your prior award letter '
          'and your current ID.',
      author: 'Maria Santos',
      office: 'Scholarship Office',
      createdAt: DateTime.parse(ago(2880)),
    ),
  ];

  // ==========================================================
  // DOCUMENT
  // ==========================================================

  return <String, dynamic>{
    'schema': 1,
    'settings': settings.toMap(),
    'courses': courses.map((Course c) => c.toMap()).toList(),
    'offices': offices.map((Office o) => o.toMap()).toList(),
    'announcements': announcements.map((Announcement a) => a.toMap()).toList(),
    'accounts': accounts,
    'batches': batches.map((QueueBatch b) => b.toMap()).toList(),
    'tickets': tickets,
    'sequences': sequences,
    'reset_codes': <String, dynamic>{},
  };
}
