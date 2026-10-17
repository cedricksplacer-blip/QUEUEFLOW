import 'announcement.dart';
import 'campus_catalog.dart';
import 'student.dart';

/// ============================================================
/// QUEUEFLOW TMC — APP BOOTSTRAP PAYLOAD
///
/// `GET /api/student/bootstrap` hydrates the entire app shell in one
/// round trip: the signed-in student, public settings, announcements,
/// courses and the office catalogue.
/// ============================================================

class StudentBootstrap {
  final Student student;
  final SystemSettings settings;
  final List<Announcement> announcements;
  final List<Course> courses;
  final List<Office> offices;

  const StudentBootstrap({
    required this.student,
    this.settings = const SystemSettings(),
    this.announcements = const <Announcement>[],
    this.courses = const <Course>[],
    this.offices = const <Office>[],
  });

  /// Offices that will actually let this student take a ticket right now.
  List<Office> get openOffices => offices
      .where((Office o) => o.isActive && o.accepting)
      .toList(growable: false);

  /// The student's own course row, resolved from the profile code.
  Course? get ownCourse {
    final String? code = student.course;

    if (code == null || code.isEmpty) {
      return null;
    }

    for (final Course course in courses) {
      if (course.code.toUpperCase() == code.toUpperCase()) {
        return course;
      }
    }

    return null;
  }

  /// The course id required when taking a ticket. Falls back to the
  /// student's own course so a profile without a linked course row can
  /// still queue at non-Registrar offices.
  int? get queueCourseId => ownCourse?.id;

  Office? officeById(int id) {
    for (final Office office in offices) {
      if (office.id == id) {
        return office;
      }
    }

    return null;
  }

  factory StudentBootstrap.fromMap(Map<String, dynamic> map) =>
      StudentBootstrap(
        student: Student.fromMap(
          Map<String, dynamic>.from(map['student'] as Map),
        ),
        settings: SystemSettings.fromMap(
          Map<String, dynamic>.from(
            (map['settings'] ?? <String, dynamic>{}) as Map,
          ),
        ),
        announcements: _list(map['announcements'], Announcement.fromMap),
        courses: _list(map['courses'], Course.fromMap),
        offices: _list(map['offices'], Office.fromMap),
      );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'student': student.toMap(),
    'settings': settings.toMap(),
    'announcements': announcements.map((Announcement a) => a.toMap()).toList(),
    'courses': courses.map((Course c) => c.toMap()).toList(),
    'offices': offices.map((Office o) => o.toMap()).toList(),
  };
}

List<T> _list<T>(dynamic raw, T Function(Map<String, dynamic>) parse) {
  if (raw is! List) {
    return <T>[];
  }

  return raw
      .whereType<Map>()
      .map((Map e) => parse(Map<String, dynamic>.from(e)))
      .toList();
}
