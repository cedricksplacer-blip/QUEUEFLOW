import '../core/app_constants.dart';
import '../core/qf_value.dart';

/// ============================================================
/// QUEUEFLOW TMC — STUDENT ACCOUNT
///
/// Maps to the `users` table (role = 'student') exactly as the
/// Laravel student portal reads it.
/// ============================================================

class Student {
  final int id;
  final String name;
  final String email;
  final String role;
  final String? studentId;
  final String? course;
  final String? block;
  final String? yearLevel;
  final String? phone;

  /// The photograph to show on this student's profile card.
  ///
  /// The `users.avatar` column, falling back to the bundled portrait in
  /// [AppConstants.profilePhoto] when the account has never uploaded one.
  /// Never null, so no screen has to invent a face for a row that has none.
  final String avatar;

  final bool isActive;
  final DateTime? createdAt;

  const Student({
    required this.id,
    required this.name,
    required this.email,
    this.role = AppConstants.roleStudent,
    this.studentId,
    this.course,
    this.block,
    this.yearLevel,
    this.phone,
    this.avatar = AppConstants.profilePhoto,
    this.isActive = true,
    this.createdAt,
  });

  bool get isStudent => role == AppConstants.roleStudent;

  /// Up to two uppercase letters for the avatar bubble.
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

  /// e.g. "BSIT • 2nd Year • Block 1"
  String get academicSummary {
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

  // ==========================================================
  // SERIALISATION
  // ==========================================================

  factory Student.fromMap(Map<String, dynamic> map) => Student(
    id: QfValue.toInt(map['id']),
    name: QfValue.toStr(map['name']),
    email: QfValue.toStr(map['email']),
    role: QfValue.toStrOrNull(map['role']) ?? AppConstants.roleStudent,
    studentId: QfValue.toStrOrNull(map['student_id']),
    course: QfValue.toStrOrNull(map['course']),
    block: QfValue.toStrOrNull(map['block']),
    yearLevel: QfValue.toStrOrNull(map['year_level']),
    phone: QfValue.toStrOrNull(map['phone']),
    avatar:
        QfValue.toStrOrNull(map['avatar']) ?? AppConstants.profilePhoto,
    isActive: QfValue.toBool(map['is_active'], fallback: true),
    createdAt: QfValue.toDate(map['created_at']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'name': name,
    'email': email,
    'role': role,
    'student_id': studentId,
    'course': course,
    'block': block,
    'year_level': yearLevel,
    'phone': phone,
    'avatar': avatar,
    'is_active': isActive,
    'created_at': createdAt?.toIso8601String(),
  };

  Student copyWith({
    String? name,
    String? email,
    String? studentId,
    String? block,
    String? yearLevel,
    String? phone,
    String? avatar,
  }) => Student(
    id: id,
    name: name ?? this.name,
    email: email ?? this.email,
    role: role,
    studentId: studentId ?? this.studentId,
    course: course,
    block: block ?? this.block,
    yearLevel: yearLevel ?? this.yearLevel,
    phone: phone ?? this.phone,
    avatar: avatar ?? this.avatar,
    isActive: isActive,
    createdAt: createdAt,
  );
}
