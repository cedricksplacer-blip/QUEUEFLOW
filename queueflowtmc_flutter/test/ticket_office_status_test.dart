import 'package:flutter_test/flutter_test.dart';
import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';

/// The receipt prints the counter's online/open state, so the office reference
/// a ticket carries must survive the JSON envelope the Laravel API sends.
/// These tests pin that decode, and the fallback used when a transport predates
/// the field.
void main() {
  group('OfficeRef office status', () {
    test('reads the online/open state the server resolved', () {
      final OfficeRef office = OfficeRef.fromMap(<String, dynamic>{
        'id': 3,
        'name': 'Cashier',
        'code': 'CASH',
        'status': AppConstants.officeStatusOnline,
        'window_open': true,
        'accepting': true,
      });

      expect(office.isOnline, isTrue);
      expect(office.windowOpen, isTrue);
      expect(office.accepting, isTrue);
      expect(office.isOpen, isTrue);
    });

    test('reports an offline office as closed even inside its hours', () {
      final OfficeRef office = OfficeRef.fromMap(<String, dynamic>{
        'id': 3,
        'name': 'Cashier',
        'code': 'CASH',
        'status': AppConstants.officeStatusOffline,
        'window_open': true,
        'accepting': false,
      });

      expect(office.isOnline, isFalse);
      expect(office.isOpen, isFalse);
    });

    test('an online office outside its hours is not open', () {
      final OfficeRef office = OfficeRef.fromMap(<String, dynamic>{
        'id': 4,
        'name': 'Cashier',
        'code': 'CASH',
        'status': AppConstants.officeStatusOnline,
        'window_open': false,
        'accepting': false,
      });

      expect(office.isOnline, isTrue);
      expect(office.isOpen, isFalse);
    });

    test('defaults to offline when an older payload omits the switch', () {
      final OfficeRef office = OfficeRef.fromMap(<String, dynamic>{
        'id': 5,
        'name': 'Registrar',
        'code': 'REG-BSIT',
      });

      expect(office.status, AppConstants.officeStatusOffline);
      expect(office.isOpen, isFalse);
    });

    test('a ticket carries the office status through from the server', () {
      final QueueTicket ticket = QueueTicket.fromMap(<String, dynamic>{
        'id': 77,
        'queue_number': 'REG-BSIT-001',
        'status': AppConstants.statusWaiting,
        'office': <String, dynamic>{
          'id': 2,
          'name': 'Registrar — BSIT',
          'code': 'REG-BSIT',
          'status': AppConstants.officeStatusOnline,
          'window_open': true,
          'accepting': true,
        },
      });

      expect(ticket.office, isNotNull);
      expect(ticket.office!.isOpen, isTrue);
      expect(ticket.office!.isOnline, isTrue);
    });
  });
}
