import '../core/app_constants.dart';
import '../core/qf_value.dart';
import 'student.dart';

/// ============================================================
/// QUEUEFLOW TMC — SIGNED-IN IDENTITY
///
/// One `users` row backs every account the app accepts, so [AppUser]
/// is the canonical session identity. The role decides which shell the
/// app shows and which route group the token is allowed to call, so it
/// is modelled as an enum rather than a loose string that each screen
/// has to re-compare.
///
/// The academic columns (course, block, year level, student number)
/// only apply to students, so they are nullable here and null on an
/// admin or staff row.
/// ============================================================

/// The roles the `users.role` column can hold, plus a fallback for a
/// value this build does not recognise.
///
/// [unknown] is deliberately not a signed-in-capable role: [canOperateQueue]
/// and [isStudent] are both false for it, so an unrecognised role lands on
/// the login screen instead of being handed a queue console.
enum AppRole {
  admin(AppConstants.roleAdmin, 'Administrator'),
  staff(AppConstants.roleStaff, 'Staff'),
  student(AppConstants.roleStudent, 'Student'),
  unknown('', 'Guest');

  const AppRole(this.wire, this.label);

  /// The value stored in the `users.role` column.
  final String wire;

  /// Human-facing name, used in the drawer and the role badge.
  final String label;

  bool get isAdmin => this == AppRole.admin;
  bool get isStaff => this == AppRole.staff;
  bool get isStudent => this == AppRole.student;

  /// True for the roles that call and serve queue numbers.
  bool get canOperateQueue => isAdmin || isStaff;

  /// Resolves a `role` column value, case- and whitespace-insensitively.
  static AppRole fromWire(dynamic raw) {
    final String value = QfValue.toStr(raw).trim().toLowerCase();

    for (final AppRole role in AppRole.values) {
      if (role != AppRole.unknown && role.wire == value) {
        return role;
      }
    }

    return AppRole.unknown;
  }
}

/// A row from the `users` table, for any role.
class AppUser {
  /// The courses a student row may carry.
  ///
  /// The server validates `course` with an `in:` rule against `User::COURSES`,
  /// so this is the same seven values the database will accept. It exists here
  /// rather than as free text in a form because a text box cannot tell the
  /// difference between "BSIT" and "BS Information Technology", and only the
  /// first survives the round trip - the second comes back as a raw validation
  /// error. Keep in step with `app/Models/User.php`.
  static const List<String> courses = <String>[
    'BSIT',
    'BSOA',
    'BSCRIM',
    'BEED',
    'BSED',
    'BACOMM',
    'BAPOLSCI',
  ];

  /// The year levels a student row may carry, as the server spells them.
  ///
  /// Note the spelling: "1st Year", not "1". The column is validated with an
  /// `in:` rule against `User::YEAR_LEVELS`, so a digit is refused. Keep in
  /// step with `app/Models/User.php`.
  static const List<String> yearLevels = <String>[
    '1st Year',
    '2nd Year',
    '3rd Year',
    '4th Year',
  ];

  final int id;
  final String name;
  final String email;
  final AppRole role;

  /// Student-only columns. Null for admin and staff accounts.
  final String? studentId;
  final String? course;
  final String? block;
  final String? yearLevel;

  final String? phone;

  /// The photograph this account shows on its profile card.
  ///
  /// Lives here rather than only on [Student] because the column is on the
  /// `users` row, and the student payload is projected through this model — an
  /// `avatar` declared on one and not the other would be silently dropped on
  /// the way to the dashboard. Never null: an account with no upload of its own
  /// falls back to the bundled portrait.
  final String avatar;

  final bool isActive;
  final DateTime? createdAt;

  /// The counter a staff member is assigned to, or null for every other role.
  ///
  /// Carried on the identity because it decides what a staff member is allowed
  /// to touch: the server will only let them work the office they are assigned
  /// to, so the app has to be able to show - and resend - that assignment when
  /// it rewrites an account row.
  final int? officeId;

  const AppUser({
    required this.id,
    required this.name,
    required this.email,
    this.role = AppRole.student,
    this.studentId,
    this.course,
    this.block,
    this.yearLevel,
    this.phone,
    this.avatar = AppConstants.profilePhoto,
    this.isActive = true,
    this.createdAt,
    this.officeId,
  });

  String get initials {
    final List<String> parts = name
        .trim()
        .split(RegExp(r'\s+'))
        .where((String p) => p.isNotEmpty)
        .toList();

    if (parts.isEmpty) {
      return '?';
    }

    if (parts.length == 1) {
      final String only = parts.first;

      return (only.length >= 2 ? only.substring(0, 2) : only).toUpperCase();
    }

    return '${parts.first[0]}${parts.last[0]}'.toUpperCase();
  }

  /// The second line under the name in the drawer.
  ///
  /// A student gets their academic summary, which is what identifies them
  /// to a member of staff. An admin or staff member has no academic row,
  /// so their role stands in — and saying "Student" under an admin's name
  /// in the shared drawer would be actively misleading.
  String get identitySummary {
    if (!role.isStudent) {
      return role.label;
    }

    final List<String> parts = <String>[];

    if (course != null && course!.isNotEmpty) {
      parts.add(course!);
    }

    if (yearLevel != null && yearLevel!.isNotEmpty) {
      parts.add(yearLevel!);
    }

    if (block != null && block!.isNotEmpty) {
      parts.add('Block $block');
    }

    return parts.isEmpty ? 'No course assigned yet' : parts.join(' • ');
  }

  /// The student-shaped view of this row.
  ///
  /// The student shell was written against [Student], and the two models
  /// cover the same columns, so the projection goes through [toMap] rather
  /// than restating every field. It keeps a `role` of `admin` intact, which
  /// matters if a staff account is ever inspected through student code.
  Student get asStudent => Student.fromMap(toMap());

  factory AppUser.fromStudent(Student student) => AppUser(
    id: student.id,
    name: student.name,
    email: student.email,
    role: AppRole.fromWire(student.role),
    studentId: student.studentId,
    course: student.course,
    block: student.block,
    yearLevel: student.yearLevel,
    phone: student.phone,
    avatar: student.avatar,
    isActive: student.isActive,
    createdAt: student.createdAt,
  );

  factory AppUser.fromMap(Map<String, dynamic> map) => AppUser(
    id: QfValue.toInt(map['id']),
    name: QfValue.toStr(map['name']),
    email: QfValue.toStr(map['email']),
    role: AppRole.fromWire(map['role']),
    studentId: QfValue.toStrOrNull(map['student_id']),
    course: QfValue.toStrOrNull(map['course']),
    block: QfValue.toStrOrNull(map['block']),
    yearLevel: QfValue.toStrOrNull(map['year_level']),
    phone: QfValue.toStrOrNull(map['phone']),
    avatar: QfValue.toStrOrNull(map['avatar']) ?? AppConstants.profilePhoto,
    isActive: QfValue.toBool(map['is_active'], fallback: true),
    createdAt: QfValue.toDate(map['created_at']),
    // A zero means "no office", which is a real value on this model, so it is
    // collapsed to null rather than decoded as counter zero.
    officeId: () {
      final int value = QfValue.toInt(map['office_id']);

      return value > 0 ? value : null;
    }(),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'name': name,
    'email': email,
    'role': role.wire.isEmpty ? AppConstants.roleStudent : role.wire,
    'student_id': studentId,
    'course': course,
    'block': block,
    'year_level': yearLevel,
    'phone': phone,
    'avatar': avatar,
    'is_active': isActive,
    'created_at': createdAt?.toIso8601String(),
    'office_id': officeId,
  };
}
