Option Strict On
Option Explicit On
Imports System.Net.Http
Imports System.Text.Json
Imports System.Threading.Tasks

Public Class AuditTrailForm
    Inherits Form
    Private ReadOnly grid As New DataGridView()
    Private ReadOnly refreshButton As New Button()
    Private ReadOnly statusLabel As New Label()
    Private ReadOnly fromPicker As New DateTimePicker()
    Private ReadOnly toPicker As New DateTimePicker()
    Private ReadOnly filterBox As New TextBox()

    Public Sub New()
        Text = "Audit Trail"
        StartPosition = FormStartPosition.CenterParent
        MinimumSize = New Size(900, 560)
        Size = New Size(1100, 680)
        Dim top As New FlowLayoutPanel With {.Dock = DockStyle.Top, .Height = 78, .Padding = New Padding(10)}
        fromPicker.Format = DateTimePickerFormat.Short
        fromPicker.Value = Date.Today.AddDays(-30)
        toPicker.Format = DateTimePickerFormat.Short
        toPicker.Value = Date.Today
        filterBox.Width = 180
        refreshButton.Text = "Refresh"
        refreshButton.AutoSize = True
        AddHandler refreshButton.Click, Async Sub() Await LoadEventsAsync()
        top.Controls.AddRange({New Label With {.Text = "From"}, fromPicker, New Label With {.Text = "To"}, toPicker, New Label With {.Text = "Event/user filter"}, filterBox, refreshButton})
        grid.Dock = DockStyle.Fill
        grid.ReadOnly = True
        grid.AllowUserToAddRows = False
        grid.AllowUserToDeleteRows = False
        grid.AutoGenerateColumns = True
        grid.SelectionMode = DataGridViewSelectionMode.FullRowSelect
        grid.AutoSizeColumnsMode = DataGridViewAutoSizeColumnsMode.Fill
        statusLabel.Dock = DockStyle.Bottom
        statusLabel.Height = 30
        statusLabel.Padding = New Padding(10, 7, 0, 0)
        statusLabel.Text = "Requires the API audit-log endpoint; not connected until backend work is complete."
        Controls.Add(grid)
        Controls.Add(statusLabel)
        Controls.Add(top)
    End Sub

    Private Async Function LoadEventsAsync() As Task
        If ApiSession.Client Is Nothing Then
            statusLabel.Text = "No API session. Please sign in again."
            Return
        End If
        refreshButton.Enabled = False
        Try
            Dim url = "/audit-logs?from=" & Uri.EscapeDataString(fromPicker.Value.ToString("yyyy-MM-dd")) &
                      "&to=" & Uri.EscapeDataString(toPicker.Value.ToString("yyyy-MM-dd")) &
                      "&filter=" & Uri.EscapeDataString(filterBox.Text.Trim())
            Using response = Await ApiSession.Client.GetAsync(url)
                Dim body = Await response.Content.ReadAsStringAsync()
                If Not response.IsSuccessStatusCode Then
                    statusLabel.Text = "Audit API is not ready or access is denied (HTTP " & CInt(response.StatusCode).ToString() & ")."
                    Return
                End If
                Using doc = JsonDocument.Parse(body)
                    Dim array As JsonElement
                    If doc.RootElement.ValueKind = JsonValueKind.Array Then
                        array = doc.RootElement
                    ElseIf Not (doc.RootElement.TryGetProperty("data", array) OrElse doc.RootElement.TryGetProperty("events", array)) Then
                        statusLabel.Text = "Unexpected API response format."
                        Return
                    End If
                    Dim rows As New List(Of AuditEventRow)
                    For Each item In array.EnumerateArray()
                        rows.Add(New AuditEventRow With {
                            .OccurredAt = ReadField(item, "occurred_at", "created_at"),
                            .Actor = ReadField(item, "actor_name", "user_name", "email"),
                            .Action = ReadField(item, "action", "event"),
                            .Area = ReadField(item, "area", "module"),
                            .RecordReference = ReadField(item, "record_reference", "subject"),
                            .Outcome = ReadField(item, "outcome", "status"),
                            .IpAddress = ReadField(item, "ip_address")
                        })
                    Next
                    grid.DataSource = rows
                    statusLabel.Text = rows.Count.ToString() & " event(s) loaded."
                End Using
            End Using
        Catch ex As HttpRequestException
            statusLabel.Text = "Cannot reach API: " & ex.Message
        Catch ex As TaskCanceledException
            statusLabel.Text = "Request timed out."
        Catch ex As JsonException
            statusLabel.Text = "Invalid JSON returned by API."
        Finally
            refreshButton.Enabled = True
        End Try
    End Function

    Private Shared Function ReadField(item As JsonElement, ParamArray names As String()) As String
    For Each name As String In names
        Dim value As JsonElement

        If item.ValueKind = JsonValueKind.Object AndAlso
        item.TryGetProperty(name, value) Then

            If value.ValueKind = JsonValueKind.String Then
                Return If(value.GetString(), "")
            End If

            If value.ValueKind <> JsonValueKind.Null AndAlso
            value.ValueKind <> JsonValueKind.Undefined Then
                Return value.ToString()
            End If
        End If
    Next

    Return String.Empty
End Function

    Private Class AuditEventRow
        Public Property OccurredAt As String = ""
        Public Property Actor As String = ""
        Public Property Action As String = ""
        Public Property Area As String = ""
        Public Property RecordReference As String = ""
        Public Property Outcome As String = ""
        Public Property IpAddress As String = ""
    End Class
End Class
