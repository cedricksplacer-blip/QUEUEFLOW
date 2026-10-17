import 'dart:convert';

import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/app_config.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/services/campus_backend.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';
import 'package:queueflow_tmc/services/session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — THE REMOTE TRANSPORT
///
/// [RemoteBackend] is the only code in the app that knows a server exists, so
/// these tests drive it against a stub HTTP client and assert the three things
/// that would hurt a real campus if they were wrong:
///
///   - the bearer token travels on every call after a sign-in, and stops
///     travelling after a sign-out;
///   - a refusal is surfaced with the server's own sentence and its status,
///     because the session logic reads 401 off the status and the screens read
///     the message;
///   - nothing is invented. A reset code is never returned, and a counter
///     action with no counter selected is refused before a request is made.
///
/// No test here reaches a network: [MockClient] answers from a closure, so the
/// whole suite runs offline and deterministically.
/// ============================================================
void main() {
  /// One recorded request, so a test can assert on what was actually sent.
  final List<http.Request> sent = <http.Request>[];

  /// Builds a backend whose client answers with [handler].
  RemoteBackend backendAnswering(
    http.Response Function(http.Request request) handler,
  ) => RemoteBackend(
    client: ApiClient(
      client: MockClient((http.Request request) {
        sent.add(request);

        return Future<http.Response>.value(handler(request));
      }),
      tokenProvider: () => SessionStore.token,
    ),
  );

  http.Response json(Object body, {int status = 200}) => http.Response(
    jsonEncode(body),
    status,
    headers: <String, String>{
      'content-type': 'application/json; charset=utf-8',
    },
  );

  const Map<String, dynamic> studentPayload = <String, dynamic>{
    'user': <String, dynamic>{
      'id': 7,
      'name': 'Juan Dela Cruz',
      'email': 'juan@tmc.edu.ph',
      'role': 'student',
      'student_id': '24-019083',
      'course': 'BSIT',
      'is_active': true,
    },
  };

  /// The same student as a model, for the calls that take the signed-in user.
  final AppUser studentUser =
      AppUser.fromMap(studentPayload['user']! as Map<String, dynamic>);

  setUp(() async {
    sent.clear();

    // The bearer token is kept in the platform keychain, which does not exist
    // in a test process; the package's in-memory stand-in is installed so the
    // vault behaves as it does on a device.
    FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(
      <String, String>{},
    );

    // `load` reads the preference store once per process, which is what an app
    // wants and what a test has to undo by hand: clearing the cached session
    // first stops one test's token from reaching the next.
    await SessionStore.clear();
    SharedPreferences.setMockInitialValues(<String, Object>{});
    await SessionStore.load();
  });

  group('sign-in', () {
    test('stores the token and sends it on every later call', () async {
      final RemoteBackend backend = backendAnswering((http.Request request) {
        if (request.url.path.endsWith('/auth/login')) {
          return json(<String, dynamic>{
            ...studentPayload,
            'token': 'test-token-abc',
            'token_type': 'Bearer',
          });
        }

        return json(studentPayload);
      });

      final AppUser user = await backend.signIn(
        identifier: '24-019083',
        password: 'secret123',
      );

      expect(user.id, 7);
      expect(user.role, AppRole.student);
      expect(SessionStore.token, 'test-token-abc');

      // The ID is sent as typed: the server compares the stored Student ID
      // exactly, so normalising it here would break the match.
      final http.Request login = sent.first;
      expect(
        login.url.toString(),
        endsWith('${AppConfig.apiPrefix}/auth/login'),
      );
      expect(login.url.query, isEmpty);
      expect(login.headers['Authorization'], isNull);
      expect(jsonDecode(login.body), containsPair('student_id', '24-019083'));

      await backend.account(user);

      final http.Request me = sent.last;
      expect(me.url.path, endsWith('/auth/me'));
      expect(me.headers['Authorization'], 'Bearer test-token-abc');
    });

    test('a wrong password keeps the refusal sentence and the 401', () async {
      final Map<String, dynamic> refusal = <String, dynamic>{
        'message': 'Those credentials do not match our records.',
      };
      final RemoteBackend backend = backendAnswering(
        (_) => json(refusal, status: 401),
      );

      // A CampusBackendException, not an ApiException: that is what the
      // CampusBackend contract promises, and it is what AuthRepository catches.
      // An ApiException escaping here used to sail past every handler and tear
      // out of a sign-in the repository believed it had reported.
      await expectLater(
        backend.signIn(identifier: '24-019083', password: 'wrong'),
        throwsA(
          isA<CampusBackendException>()
              .having(
                (CampusBackendException e) => e.message,
                'message',
                'Those credentials do not match our records.',
              )
              .having(
                (CampusBackendException e) => e.statusCode,
                'statusCode',
                401,
              )
              .having(
                (CampusBackendException e) => e.isUnauthenticated,
                'isUnauthenticated',
                isTrue,
              ),
        ),
      );

      // A failed sign-in must not leave a token behind for the next call to
      // send by accident.
      expect(SessionStore.token, isNull);
    });

    test('signing out revokes the token and forgets it', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(studentPayload),
      );

      await SessionStore.save(
        user: AppUser.fromMap(studentPayload['user']! as Map<String, dynamic>),
        token: 'test-token-abc',
      );

      await backend.signOut();

      expect(sent.single.url.path, endsWith('/auth/logout'));
      expect(sent.single.headers['Authorization'], 'Bearer test-token-abc');

      // Local state is cleared even though the server answered: a device that
      // says it is signed out must be signed out.
      expect(SessionStore.hasSession, isFalse);
      expect(SessionStore.token, isNull);
    });
  });

  group('profile', () {
    test('a self-service edit sends no name or student id', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(studentPayload),
      );

      await backend.updateProfile(
        actor: studentUser,
        email: 'new@tmc.edu.ph',
        phone: '09170000000',
      );

      final http.Request patch = sent.last;
      expect(patch.url.path, endsWith('/auth/me'));

      final Map<String, dynamic> body =
          jsonDecode(patch.body) as Map<String, dynamic>;

      // Name and student number belong to an administrator: they must not
      // even leave the device on the self-service endpoint.
      expect(body.containsKey('name'), isFalse);
      expect(body.containsKey('student_id'), isFalse);
      expect(body, containsPair('email', 'new@tmc.edu.ph'));
      expect(body, containsPair('phone', '09170000000'));
    });
  });

  group('counter actions', () {
    setUp(() async {
      sent.clear();

      await SessionStore.save(
        user: studentUser,
        token: 'test-token-abc',
      );
    });

    test('a named call sends the ticket id it was given', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(<String, dynamic>{
          'ticket': <String, dynamic>{
            'id': 91,
            'queue_number': 'REG-BSIT-001',
            'status': 'serving',
          },
        }),
      );

      await backend.callNext(officeId: 3, ticketId: 91);

      final http.Request call = sent.single;
      expect(call.url.path, endsWith('/queues/3/call-next'));
      expect(call.method, 'POST');
      expect(jsonDecode(call.body), <String, dynamic>{'ticket_id': 91});
    });

    test('calling with no counter is refused before any request', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(<String, dynamic>{
          'ticket': <String, dynamic>{'id': 1},
        }),
      );

      // The route is office-scoped, so there is no honest URL to build without
      // one. Failing here keeps a half-formed path off the wire.
      await expectLater(
        backend.callNext(),
        throwsA(
          isA<CampusBackendException>().having(
            (CampusBackendException e) => e.message,
            'message',
            'Pick a counter first.',
          ),
        ),
      );

      expect(sent, isEmpty);
    });

    test('serving does not let the client choose who served the ticket', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(<String, dynamic>{
          'ticket': <String, dynamic>{
            'id': 91,
            'status': 'served',
            'served_by': 4,
          },
        }),
      );

      // The actor is passed so a caller cannot forget it, but the server stamps
      // `served_by` from the token, so nothing identifying is put on the wire.
      await backend.serveTicket(91, studentUser, officeId: 3);

      final http.Request serve = sent.single;
      expect(serve.url.path, endsWith('/queues/3/tickets/91/serve'));
      // An action with no parameters sends an empty object rather than no body
      // at all. What matters is that nothing identifying is in it: served_by
      // comes from the token, so the client cannot credit the ticket to
      // somebody else.
      expect(jsonDecode(serve.body), isEmpty);
    });
  });

  group('password recovery', () {
    test(
      'never returns a code, because none is ever sent to the client',
      () async {
        final Map<String, dynamic> mailed = <String, dynamic>{
          'message': 'If that account can receive mail, a code is on its way.',
          'expires_in_minutes': 10,
        };
        final RemoteBackend backend = backendAnswering((_) => json(mailed));

        final ResetCodeIssued issued = await backend.requestResetCode(
          'juan@tmc.edu.ph',
        );

        expect(issued.code, isNull);
        expect(issued.hasCode, isFalse);
        expect(issued.message, contains('on its way'));
        expect(issued.expiresInMinutes, 10);

        // The screen branches on hasCode, so a null here is what stops it
        // displaying a number nobody was issued.
        expect(issued.retryAfterSeconds, isNull);
      },
    );

    test('a cool-down is passed through so the form can wait', () async {
      final Map<String, dynamic> throttled = <String, dynamic>{
        'message': 'A code was requested very recently.',
        'retry_after': 42,
      };
      final RemoteBackend backend = backendAnswering(
        (_) => json(throttled, status: 429),
      );

      // A 429 carries its sentence in the body, and the value that tells the
      // form how long to hold off. The status survives the translation into the
      // CampusBackend contract, because the form has to be able to tell "you
      // asked too soon" apart from "that account does not exist".
      await expectLater(
        backend.requestResetCode('juan@tmc.edu.ph'),
        throwsA(
          isA<CampusBackendException>()
              .having(
                (CampusBackendException e) => e.message,
                'message',
                'A code was requested very recently.',
              )
              .having(
                (CampusBackendException e) => e.statusCode,
                'statusCode',
                429,
              ),
        ),
      );
    });

    test('a verified code yields the token the next step needs', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(<String, dynamic>{
          'message': 'Code verified.',
          'reset_token': 'tok-abc-123',
          'expires_in_minutes': 10,
        }),
      );

      final String token = await backend.verifyCode(
        identifier: 'juan@tmc.edu.ph',
        code: '483920',
      );

      expect(token, 'tok-abc-123');
      expect(sent.single.url.path, contains('/auth/verify-code'));
      expect(jsonDecode(sent.single.body), <String, dynamic>{
        'identifier': 'juan@tmc.edu.ph',
        'code': '483920',
      });
    });

    test(
      'the reset is posted with a confirmation the server expects',
      () async {
        final Map<String, dynamic> updated = <String, dynamic>{
          'message': 'Password updated.',
        };
        final RemoteBackend backend = backendAnswering((_) => json(updated));

        await backend.resetPassword(
          identifier: 'juan@tmc.edu.ph',
          code: '483920',
          password: 'brandnewpass',
        );

        expect(jsonDecode(sent.single.body), <String, dynamic>{
          'identifier': 'juan@tmc.edu.ph',
          'code': '483920',
          'password': 'brandnewpass',
          'password_confirmation': 'brandnewpass',
        });
      },
    );

    test('the stepped flow posts the verified token instead of the code', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => json(<String, dynamic>{'message': 'Password updated.'}),
      );

      await backend.resetPassword(
        identifier: 'juan@tmc.edu.ph',
        resetToken: 'tok-abc-123',
        password: 'brandnewpass',
      );

      final Map<String, dynamic> body = jsonDecode(sent.single.body);
      expect(body['reset_token'], 'tok-abc-123');
      expect(body.containsKey('code'), isFalse);
    });
  });

  group('refusals', () {
    test(
      'a validation failure surfaces the sentence written for the field',
      () async {
        final Map<String, dynamic> invalid = <String, dynamic>{
          'message': 'The identifier field is required. (and 1 more error)',
          'errors': <String, dynamic>{
            'identifier': <String>['The identifier field is required.'],
          },
        };
        final RemoteBackend backend = backendAnswering(
          (_) => json(invalid, status: 422),
        );

        // The summary Laravel writes is a developer sentence; the per-field one
        // is the human one. The transport contract has no field map to carry it
        // in, so what has to survive the translation is the per-field sentence
        // itself: that is the one the screens are written against.
        await expectLater(
          backend.requestResetCode(''),
          throwsA(
            isA<CampusBackendException>()
                .having(
                  (CampusBackendException e) => e.message,
                  'message',
                  'The identifier field is required.',
                )
                .having(
                  (CampusBackendException e) => e.statusCode,
                  'statusCode',
                  422,
                ),
          ),
        );
      },
    );

    test('a wrong API address is named as a configuration problem', () async {
      // What a build pointed at a URL with no API on it actually gets: an HTML
      // 404 page rather than JSON.
      final RemoteBackend backend = backendAnswering(
        (_) => http.Response('<!DOCTYPE html><html>Not Found</html>', 404),
      );

      await expectLater(
        backend.studentBootstrap(studentUser),
        throwsA(
          isA<CampusBackendException>()
              .having(
                (CampusBackendException e) => e.message,
                'message',
                contains('configured API address'),
              )
              .having(
                (CampusBackendException e) => e.statusCode,
                'statusCode',
                404,
              ),
        ),
      );
    });

    test('an unreadable 200 is refused rather than read as empty', () async {
      final RemoteBackend backend = backendAnswering(
        (_) => http.Response('', 200),
      );

      // A truncated or empty 200 is a server fault. Returning an empty map
      // would hide it behind a screen with nothing in it, and would then fail
      // inside the model on a field that was never sent.
      await expectLater(
        backend.studentBootstrap(studentUser),
        throwsA(
          isA<CampusBackendException>()
              .having(
                (CampusBackendException e) => e.message,
                'message',
                contains('empty response'),
              )
              .having(
                (CampusBackendException e) => e.statusCode,
                'statusCode',
                200,
              ),
        ),
      );
    });
  });
}
