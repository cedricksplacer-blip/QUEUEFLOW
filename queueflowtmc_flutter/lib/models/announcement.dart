import '../core/qf_value.dart';

/// ============================================================
/// QUEUEFLOW TMC — ANNOUNCEMENTS & SYSTEM SETTINGS
///
/// Maps to the `announcements` and `settings` tables.
/// ============================================================

class Announcement {
  /// Audience values stored in `announcements.audience`.
  /// Mirrors `App\Models\Announcement::AUDIENCES` on the server.
  static const String audienceUser = 'user';
  static const String audienceStaff = 'staff';
  static const String audienceBoth = 'both';

  final int id;
  final String title;
  final String body;
  final String status;
  final bool isActive;
  final String audience;
  final String? author;
  final String? office;
  final DateTime? createdAt;

  const Announcement({
    required this.id,
    required this.title,
    required this.body,
    this.status = 'published',
    this.isActive = true,
    this.audience = audienceBoth,
    this.author,
    this.office,
    this.createdAt,
  });

  /// Normalises a raw audience value; unknown or missing values fall back
  /// to 'both' so legacy payloads stay visible to everyone.
  static String parseAudience(dynamic raw) {
    final String value =
        QfValue.toStrOrNull(raw)?.trim().toLowerCase() ?? '';
    if (value == audienceUser || value == 'student') return audienceUser;
    if (value == audienceStaff) return audienceStaff;
    return audienceBoth;
  }

  factory Announcement.fromMap(Map<String, dynamic> map) => Announcement(
    id: QfValue.toInt(map['id']),
    title: QfValue.toStr(map['title']),
    body: QfValue.toStr(map['body']),
    status: QfValue.toStrOrNull(map['status']) ?? 'published',
    isActive: QfValue.toBool(map['is_active'], fallback: true),
    audience: parseAudience(map['audience']),
    author: QfValue.toStrOrNull(map['author']),
    office: QfValue.toStrOrNull(map['office']),
    createdAt: QfValue.toLocalDate(map['created_at']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'title': title,
    'body': body,
    'status': status,
    'is_active': isActive,
    'audience': audience,
    'author': author,
    'office': office,
    'created_at': createdAt?.toIso8601String(),
  };

  /// Whether a student is meant to see this one.
  ///
  /// Both halves matter: the `announcements` table carries drafts and archived
  /// rows alongside published ones, and an active flag that was never cleared.
  /// Rendering either would put an unsent notice in front of the whole campus,
  /// so both are required rather than either.
  bool get isPublished =>
      isActive && status.trim().toLowerCase() == 'published';

  /// Whether this notice targets the user (student) audience.
  bool get isForUser =>
      audience == audienceUser || audience == audienceBoth;

  /// Whether this notice targets the staff audience.
  bool get isForStaff =>
      audience == audienceStaff || audience == audienceBoth;

  /// Whether this notice should be shown to the given role wire value
  /// ('student'/'user', 'staff', or anything else for admin/all).
  bool isVisibleToRole(String role) {
    final String value = role.trim().toLowerCase();
    if (value == 'staff') return isForStaff;
    if (value == 'student' || value == 'user') return isForUser;
    return true;
  }

  /// Published and targeted at the given role.
  bool isLiveForRole(String role) => isPublished && isVisibleToRole(role);
}

/// Public system settings a student client is allowed to read.
///
/// Mirrors the allow-list in App\Support\StudentPortalPresenter::settings().
class SystemSettings {
  final String siteName;
  final String collegeName;
  final String systemDescription;
  final String contactEmail;
  final String contactNumber;

  final int defaultWaitingTime;
  final String defaultOpeningTime;
  final String defaultClosingTime;
  final String operationalStartTime;
  final String operationalEndTime;

  final int maxActiveTicketsPerStudent;
  final int maxSlotsPerStation;
  final int minimumStudentsPerStation;
  final int targetStudentsPerStation;

  final List<String> supportedCourses;
  final List<String> supportedYearLevels;

  final bool maintenanceMode;
  final String maintenanceMessage;
  final bool ticketCreatedNotification;
  final bool queueAlmostReadyNotification;
  final bool nowServingNotification;
  final bool ticketCompletedNotification;
  final bool ticketCancelledNotification;
  final bool announcementNotification;
  final bool showSystemLogo;

  final String primaryColor;
  final String secondaryColor;
  final String accentColor;

  const SystemSettings({
    this.siteName = 'QUEUEFLOW TMC',
    this.collegeName = 'Trinidad Municipal College',
    this.systemDescription = '',
    this.contactEmail = '',
    this.contactNumber = '',
    this.defaultWaitingTime = 5,
    this.defaultOpeningTime = '08:00',
    this.defaultClosingTime = '15:00',
    this.operationalStartTime = '08:00',
    this.operationalEndTime = '15:00',
    this.maxActiveTicketsPerStudent = 1,
    this.maxSlotsPerStation = 100,
    this.minimumStudentsPerStation = 40,
    this.targetStudentsPerStation = 60,
    this.supportedCourses = const <String>[],
    this.supportedYearLevels = const <String>[
      '1st Year',
      '2nd Year',
      '3rd Year',
      '4th Year',
    ],
    this.maintenanceMode = false,
    this.maintenanceMessage = '',
    this.ticketCreatedNotification = true,
    this.queueAlmostReadyNotification = true,
    this.nowServingNotification = true,
    this.ticketCompletedNotification = true,
    this.ticketCancelledNotification = true,
    this.announcementNotification = true,
    this.showSystemLogo = true,
    this.primaryColor = '#073B91',
    this.secondaryColor = '#1264D8',
    this.accentColor = '#FFB300',
  });

  bool get inMaintenanceMode => maintenanceMode;

  String get operatingWindow => '$operationalStartTime - $operationalEndTime';

  factory SystemSettings.fromMap(Map<String, dynamic> map) {
    String str(String key, String fallback) =>
        QfValue.toStrOrNull(map[key]) ?? fallback;

    int num_(String key, int fallback) =>
        QfValue.toIntOrNull(map[key]) ?? fallback;

    bool flag(String key, bool fallback) =>
        QfValue.toBool(map[key], fallback: fallback);

    return SystemSettings(
      siteName: str('site_name', 'QUEUEFLOW TMC'),
      collegeName: str('college_name', 'Trinidad Municipal College'),
      systemDescription: str('system_description', ''),
      contactEmail: str('contact_email', ''),
      contactNumber: str('contact_number', ''),
      defaultWaitingTime: num_('default_waiting_time', 5),
      defaultOpeningTime: str('default_opening_time', '08:00'),
      defaultClosingTime: str('default_closing_time', '15:00'),
      operationalStartTime: str('operational_start_time', '08:00'),
      operationalEndTime: str('operational_end_time', '15:00'),
      maxActiveTicketsPerStudent: num_('max_active_tickets_per_student', 1),
      maxSlotsPerStation: num_('max_slots_per_station', 100),
      minimumStudentsPerStation: num_('minimum_students_per_station', 40),
      targetStudentsPerStation: num_('target_students_per_station', 60),
      supportedCourses: QfValue.toStringList(map['supported_courses']),
      supportedYearLevels: QfValue.toStringList(map['supported_year_levels']),
      maintenanceMode: flag('maintenance_mode', false),
      maintenanceMessage: str('maintenance_message', ''),
      ticketCreatedNotification: flag('ticket_created_notification', true),
      queueAlmostReadyNotification: flag(
        'queue_almost_ready_notification',
        true,
      ),
      nowServingNotification: flag('now_serving_notification', true),
      ticketCompletedNotification: flag('ticket_completed_notification', true),
      ticketCancelledNotification: flag('ticket_cancelled_notification', true),
      announcementNotification: flag('announcement_notification', true),
      showSystemLogo: flag('show_system_logo', true),
      primaryColor: str('primary_color', '#073B91'),
      secondaryColor: str('secondary_color', '#1264D8'),
      accentColor: str('accent_color', '#FFB300'),
    );
  }

  Map<String, dynamic> toMap() => <String, dynamic>{
    'site_name': siteName,
    'college_name': collegeName,
    'system_description': systemDescription,
    'contact_email': contactEmail,
    'contact_number': contactNumber,
    'default_waiting_time': defaultWaitingTime,
    'default_opening_time': defaultOpeningTime,
    'default_closing_time': defaultClosingTime,
    'operational_start_time': operationalStartTime,
    'operational_end_time': operationalEndTime,
    'max_active_tickets_per_student': maxActiveTicketsPerStudent,
    'max_slots_per_station': maxSlotsPerStation,
    'minimum_students_per_station': minimumStudentsPerStation,
    'target_students_per_station': targetStudentsPerStation,
    'supported_courses': supportedCourses,
    'supported_year_levels': supportedYearLevels,
    'maintenance_mode': maintenanceMode,
    'maintenance_message': maintenanceMessage,
    'ticket_created_notification': ticketCreatedNotification,
    'queue_almost_ready_notification': queueAlmostReadyNotification,
    'now_serving_notification': nowServingNotification,
    'ticket_completed_notification': ticketCompletedNotification,
    'ticket_cancelled_notification': ticketCancelledNotification,
    'announcement_notification': announcementNotification,
    'show_system_logo': showSystemLogo,
    'primary_color': primaryColor,
    'secondary_color': secondaryColor,
    'accent_color': accentColor,
  };
}
